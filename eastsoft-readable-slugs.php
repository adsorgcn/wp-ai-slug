<?php
/**
 * Plugin Name: Eastsoft Readable Slugs
 * Plugin URI: https://github.com/adsorgcn/wp-ai-slug
 * Description: Automatically turn non-English post titles into clean, readable English URL slugs using AI. Falls back to the WordPress default if generation fails, so publishing is never blocked. Works with any OpenAI-compatible endpoint.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: 静水流深 (adsorgcn)
 * Author URI: https://github.com/adsorgcn
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: eastsoft-readable-slugs
 */

defined( 'ABSPATH' ) || exit;

class AISlug_Plugin {

	const OPTION = 'aislug';
	const CAP    = 'manage_options';

	/** 推荐模型(在硅基流动上实测过 slug 质量与延迟) */
	public static function models() {
		return array(
			'Qwen/Qwen3.5-9B'               => 'Qwen3.5-9B(实测最快最准,推荐)',
			'deepseek-ai/DeepSeek-V4-Flash' => 'DeepSeek-V4-Flash(备选)',
			'Qwen/Qwen3.5-27B'              => 'Qwen3.5-27B(更大杯)',
		);
	}

	public static function opts() {
		$defaults = array(
			'enabled'      => 1,
			'api_key'      => '',
			'api_base'     => 'https://api.siliconflow.cn',
			'model'        => 'Qwen/Qwen3.5-9B',
			'site_context' => '',
		);
		$saved = get_option( self::OPTION );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	public static function init() {
		self::maybe_migrate();
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'maybe_generate_slug' ), 9, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_aislug_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_aislug_test', array( __CLASS__, 'handle_test' ) );
		}
	}

	/** 从早期内部版(jiami_ai_slug)平滑迁移配置 */
	private static function maybe_migrate() {
		if ( false === get_option( self::OPTION ) ) {
			$legacy = get_option( 'jiami_ai_slug' );
			if ( is_array( $legacy ) ) {
				add_option( self::OPTION, $legacy, '', false );
			}
		}
	}

	// ------------------------------------------------------------------ 核心

	/**
	 * 保存文章时:标题含中文且没有可用的 ASCII slug 时,调 AI 生成。
	 * 任何失败都原样返回 $data —— WordPress 默认行为兜底,绝不阻塞发布。
	 *
	 * 内置防护:自动保存不触发;失败进入 10 分钟退避;需 publish_posts
	 * 能力(投稿者存草稿不消耗 API);全站每小时限额;已发布旧文编辑不换链接。
	 */
	public static function maybe_generate_slug( $data, $postarr ) {
		$opts = self::opts();
		if ( empty( $opts['enabled'] ) || '' === $opts['api_key'] ) {
			return $data;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		if ( get_transient( self::OPTION . '_backoff' ) ) {
			return $data; // 最近失败过,退避期内不再尝试
		}
		$post_types = apply_filters( 'aislug_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $data['post_type'], $post_types, true ) ) {
			return $data;
		}
		if ( ! in_array( $data['post_status'], array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			return $data;
		}
		if ( ! current_user_can( apply_filters( 'aislug_cap', 'publish_posts' ) ) ) {
			return $data;
		}

		$title = trim( wp_unslash( $data['post_title'] ) );
		if ( '' === $title || ! preg_match( '/[^\x00-\x7F]/', $title ) ) {
			return $data; // 空标题或纯 ASCII 标题:WP 自己就能生成好 slug
		}

		// 已有可用的 ASCII slug(人工指定或此前生成过)则不动
		$current = rawurldecode( (string) $data['post_name'] );
		if ( '' !== $current && ! preg_match( '/[^\x00-\x7F]/', $current ) ) {
			return $data;
		}

		// 已发布的旧文编辑时不动它的链接(想换请手工改)
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id && 'publish' === get_post_field( 'post_status', $post_id )
			&& '' !== get_post_field( 'post_name', $post_id ) ) {
			return $data;
		}

		// 全站每小时限额,防脚本刷草稿刷爆账单
		$hour_count = (int) get_transient( self::OPTION . '_hourly' );
		if ( $hour_count >= (int) apply_filters( 'aislug_hourly_limit', 60 ) ) {
			return $data;
		}
		set_transient( self::OPTION . '_hourly', $hour_count + 1, HOUR_IN_SECONDS );

		$slug = self::generate( $title, $opts );
		if ( is_wp_error( $slug ) ) {
			set_transient( self::OPTION . '_backoff', 1, 10 * MINUTE_IN_SECONDS );
			update_option( self::OPTION . '_last_error', current_time( 'mysql' ) . ' | ' . $slug->get_error_message(), false );
			return $data; // 失败回退:保持 WP 默认行为
		}

		// 核心的 wp_unique_post_slug 在本 filter 之前已经跑完,必须自己做唯一化
		$data['post_name'] = wp_unique_post_slug(
			$slug,
			$post_id,
			$data['post_status'],
			$data['post_type'],
			isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0
		);
		return $data;
	}

	/**
	 * 调 OpenAI 兼容接口(默认硅基流动)生成 slug。
	 * @return string|WP_Error
	 */
	public static function generate( $title, $opts = null ) {
		$opts = $opts ? $opts : self::opts();

		$system = '你是博客URL slug生成器。给定中文文章标题,只输出一个英文slug:'
			. '全小写,连字符分隔,3-6个英文单词,概括主题,利于SEO。'
			. '品牌名/专有名词保留其英文原拼写。'
			. '只输出slug本身,不要任何解释、引号或其他文字。';
		if ( '' !== trim( (string) $opts['site_context'] ) ) {
			$system .= "\n站点背景(供理解标题用):" . trim( $opts['site_context'] );
		}

		$body = array(
			'model'           => $opts['model'],
			'max_tokens'      => 100,
			'temperature'     => 0.2,
			'enable_thinking' => false, // 混合思考模型关闭思考,保证速度与纯净输出
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $title ),
			),
		);

		$response = wp_remote_post(
			rtrim( $opts['api_base'], '/' ) . '/v1/chat/completions',
			array(
				'timeout' => 12,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $opts['api_key'],
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', 'API 请求失败: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = isset( $json['message'] ) ? $json['message'] : ( isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code );
			return new WP_Error( 'api_error', 'API 返回错误: ' . $msg );
		}

		$choice = isset( $json['choices'][0] ) ? $json['choices'][0] : array();
		$text   = isset( $choice['message']['content'] ) ? trim( (string) $choice['message']['content'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'API 未返回内容' );
		}
		if ( isset( $choice['finish_reason'] ) && 'length' === $choice['finish_reason'] ) {
			return new WP_Error( 'truncated', '输出被 max_tokens 截断: ' . $text );
		}

		// 容错:有的模型会包引号/代码块/多行,取第一行并剥壳
		$text = trim( strtok( $text, "\n" ), " \t\"'`" );

		$slug = strtolower( sanitize_title( $text ) );
		$slug = trim( preg_replace( '/-{2,}/', '-', $slug ), '-' );
		if ( strlen( $slug ) > 80 ) {
			$slug = trim( substr( $slug, 0, 80 ), '-' );
		}
		if ( '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return new WP_Error( 'bad_slug', 'AI 返回的 slug 不合规: ' . $text );
		}
		return $slug;
	}

	// ------------------------------------------------------------------ 设置页

	public static function menu() {
		add_options_page( 'Eastsoft Readable Slugs 设置', 'Readable Slugs', self::CAP, 'eastsoft-readable-slugs', array( __CLASS__, 'render' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '权限不足' );
		}
		check_admin_referer( 'aislug_save' );
		$old = self::opts();

		$base_in = isset( $_POST['api_base'] ) ? sanitize_text_field( wp_unslash( $_POST['api_base'] ) ) : '';
		$base    = '' !== $base_in ? esc_url_raw( trim( $base_in ), array( 'https' ) ) : $old['api_base'];
		if ( '' === $base || 0 !== strpos( $base, 'https://' ) ) {
			$base = 'https://api.siliconflow.cn'; // 密钥走请求头,只允许 https
		}

		$model_in = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$model    = trim( $model_in );
		if ( '' === $model || ! preg_match( '#^[\w.\-]+(/[\w.\-]+)*$#', $model ) ) {
			$model = $old['model'];
		}

		$context = isset( $_POST['site_context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['site_context'] ) ) : $old['site_context'];

		// 密钥留空 = 保持原值(避免每次保存都要重填)
		$key_in  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$api_key = '' !== trim( $key_in ) ? trim( $key_in ) : $old['api_key'];

		$new = array(
			'enabled'      => empty( $_POST['enabled'] ) ? 0 : 1,
			'api_base'     => $base,
			'model'        => $model,
			'site_context' => $context,
			'api_key'      => $api_key,
		);
		update_option( self::OPTION, $new, false );
		delete_transient( self::OPTION . '_backoff' ); // 改完配置立即重试
		wp_safe_redirect( add_query_arg( 'saved', 1, admin_url( 'options-general.php?page=eastsoft-readable-slugs' ) ) );
		exit;
	}

	public static function handle_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '权限不足' );
		}
		check_admin_referer( 'aislug_test' );
		$result = self::generate( '春节回家高铁抢票的十个实用技巧' );
		$q      = is_wp_error( $result )
			? array( 'test_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'test_ok' => rawurlencode( $result ) );
		wp_safe_redirect( add_query_arg( $q, admin_url( 'options-general.php?page=eastsoft-readable-slugs' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '权限不足' );
		}
		$opts       = self::opts();
		$last_error = get_option( self::OPTION . '_last_error' );
		$in_backoff = (bool) get_transient( self::OPTION . '_backoff' );
		$is_preset  = array_key_exists( $opts['model'], self::models() );

		// 以下三个查询参数只是本插件自身 redirect 回来的状态提示,不改变任何数据,故不需要 nonce。
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice_saved = isset( $_GET['saved'] );
		$notice_ok    = isset( $_GET['test_ok'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['test_ok'] ) ) ) : '';
		$notice_err   = isset( $_GET['test_error'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>Eastsoft Readable Slugs 设置</h1>
			<p class="description">发文时自动把中文标题生成英文 URL slug。纯英文标题、已手工指定 slug、已发布的旧文都不会触发;生成失败自动回退默认行为并退避 10 分钟,不影响发布。</p>

			<?php if ( $notice_saved ) : ?>
				<div class="notice notice-success"><p>已保存。</p></div>
			<?php endif; ?>
			<?php if ( '' !== $notice_ok ) : ?>
				<div class="notice notice-success"><p>连接正常!测试标题"春节回家高铁抢票的十个实用技巧" → <code><?php echo esc_html( $notice_ok ); ?></code></p></div>
			<?php endif; ?>
			<?php if ( '' !== $notice_err ) : ?>
				<div class="notice notice-error"><p>测试失败:<?php echo esc_html( $notice_err ); ?></p></div>
			<?php endif; ?>
			<?php if ( $in_backoff ) : ?>
				<div class="notice notice-warning"><p>最近一次生成失败,当前处于退避期(最长 10 分钟)。保存设置可立即解除。</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aislug_save' ); ?>
				<input type="hidden" name="action" value="aislug_save">
				<table class="form-table" role="presentation">
					<tr><th>启用</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $opts['enabled'] ); ?>> 自动生成 slug</label></td></tr>
					<tr><th>API Key</th><td>
						<input type="password" name="api_key" class="regular-text" autocomplete="new-password"
							placeholder="<?php echo $opts['api_key'] ? '已配置(留空保持不变)' : 'sk-...'; ?>">
						<p class="description">硅基流动或其他 OpenAI 兼容服务的 API Key。还没有账号?<a href="https://cloud.siliconflow.cn/i/tJXyk0DQ" target="_blank" rel="noopener">用邀请链接注册</a>,双方都会获得赠送额度,而且赠送额度就足够本插件长期使用(每个 slug 不到 0.001 元)。留空表示保持现有密钥不变。</p>
					</td></tr>
					<tr><th>API 地址</th><td>
						<input type="url" name="api_base" class="regular-text" value="<?php echo esc_attr( $opts['api_base'] ); ?>">
						<p class="description">OpenAI 兼容接口地址,仅限 https。默认硅基流动。</p>
					</td></tr>
					<tr><th>模型</th><td>
						<input name="model" class="regular-text" list="aislug-models" value="<?php echo esc_attr( $opts['model'] ); ?>">
						<datalist id="aislug-models">
							<?php foreach ( self::models() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description">默认 Qwen3.5-9B(实测最快最准);也可手填任意兼容模型 ID。<?php echo $is_preset ? '' : '当前为自定义模型。'; ?></p>
					</td></tr>
					<tr><th>站点背景(可选)</th><td>
						<textarea name="site_context" class="large-text" rows="3" placeholder="例:数码评测博客;常见品牌:xiaomi、huawei、dji(帮助 AI 理解标题里的专有名词并保留正确拼写)"><?php echo esc_textarea( $opts['site_context'] ); ?></textarea>
						<p class="description">描述站点主题和常见品牌词,可显著提升专有名词的翻译准确度。</p>
					</td></tr>
				</table>
				<p><button class="button button-primary">保存设置</button></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( 'aislug_test' ); ?>
				<input type="hidden" name="action" value="aislug_test">
				<button class="button" <?php disabled( '' === $opts['api_key'] ); ?>>测试连接</button>
				<?php if ( '' === $opts['api_key'] ) : ?><span class="description" style="margin-left:8px">先保存 API Key</span><?php endif; ?>
			</form>

			<?php if ( $last_error ) : ?>
				<p class="description" style="margin-top:16px">最近一次生成失败:<code><?php echo esc_html( $last_error ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

AISlug_Plugin::init();
