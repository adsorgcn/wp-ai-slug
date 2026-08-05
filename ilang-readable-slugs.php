<?php
/**
 * Plugin Name: iLang Readable Slugs
 * Plugin URI: https://github.com/adsorgcn/wp-ai-slug
 * Description: Automatically turn non-English post titles into clean, readable English URL slugs using AI. Falls back to the WordPress default if generation fails, so publishing is never blocked. Works with any OpenAI-compatible endpoint.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: 静水流深 (adsorgcn)
 * Author URI: https://github.com/adsorgcn
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: ilang-readable-slugs
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

class AISlug_Plugin {

	const OPTION = 'aislug';
	const CAP    = 'manage_options';
	const PAGE   = 'ilang-readable-slugs';

	/**
	 * 预置模型:在 SiliconFlow 上实测过 slug 质量与延迟。
	 * 键是要发给接口的 model id,值是给人看的说明 —— 用户在下拉里看到的是后者,
	 * 永远不需要知道 model id 长什么样。
	 */
	public static function models() {
		return array(
			'Qwen/Qwen3.5-9B'               => __( 'Qwen3.5-9B — fastest and most accurate in testing (recommended)', 'ilang-readable-slugs' ),
			'deepseek-ai/DeepSeek-V4-Flash' => __( 'DeepSeek-V4-Flash — alternative', 'ilang-readable-slugs' ),
			'Qwen/Qwen3.5-27B'              => __( 'Qwen3.5-27B — larger, slower', 'ilang-readable-slugs' ),
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
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'maybe_generate_slug' ), 9, 2 );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_aislug_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_aislug_test', array( __CLASS__, 'handle_test' ) );
			// 装完插件眼睛就在这一行 —— 设置入口必须在这里,而不是让人去"设置"菜单里翻
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'ilang-readable-slugs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/** 插件列表那一行的"设置"链接;没配 key 时改叫"开始设置",把下一步说出来 */
	public static function action_links( $links ) {
		$configured = '' !== trim( (string) self::opts()['api_key'] );
		$label      = $configured
			? __( 'Settings', 'ilang-readable-slugs' )
			: __( 'Set up', 'ilang-readable-slugs' );
		$link = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			$configured ? '' : ' style="font-weight:600"',
			esc_html( $label )
		);
		array_unshift( $links, $link );
		return $links;
	}

	// ------------------------------------------------------------------ 核心

	/**
	 * 保存文章时:标题含非 ASCII 字符且没有可用的 ASCII slug 时,调 AI 生成。
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
	 * 调 OpenAI 兼容接口(默认 SiliconFlow)生成 slug。
	 *
	 * 提示词刻意写成**语言中立**:本插件对任何非英文标题都成立(日文、俄文、
	 * 阿拉伯文…),写死"中文标题"会让模型在其他语言上表现打折。
	 *
	 * @return string|WP_Error
	 */
	public static function generate( $title, $opts = null ) {
		$opts = $opts ? $opts : self::opts();

		$system = 'You generate URL slugs for blog posts. Given a post title in any language, '
			. 'output one English slug: all lowercase, hyphen-separated, 3-6 English words '
			. 'that capture the topic and read well in a URL. '
			. 'Keep brand names and proper nouns in their official English spelling. '
			. 'Output the slug itself only — no explanation, quotes or any other text.';
		if ( '' !== trim( (string) $opts['site_context'] ) ) {
			$system .= "\nSite context (to help you read the title): " . trim( $opts['site_context'] );
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
			/* translators: %s: error message from the HTTP layer */
			return new WP_Error( 'http_error', sprintf( __( 'Request failed: %s', 'ilang-readable-slugs' ), $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = isset( $json['message'] ) ? $json['message'] : ( isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code );
			/* translators: %s: error message returned by the AI provider */
			return new WP_Error( 'api_error', sprintf( __( 'The provider returned an error: %s', 'ilang-readable-slugs' ), $msg ) );
		}

		$choice = isset( $json['choices'][0] ) ? $json['choices'][0] : array();
		$text   = isset( $choice['message']['content'] ) ? trim( (string) $choice['message']['content'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'The provider returned no content.', 'ilang-readable-slugs' ) );
		}
		if ( isset( $choice['finish_reason'] ) && 'length' === $choice['finish_reason'] ) {
			/* translators: %s: the truncated model output */
			return new WP_Error( 'truncated', sprintf( __( 'Output was cut off by max_tokens: %s', 'ilang-readable-slugs' ), $text ) );
		}

		// 容错:有的模型会包引号/代码块/多行,取第一行并剥壳
		$text = trim( strtok( $text, "\n" ), " \t\"'`" );

		$slug = strtolower( sanitize_title( $text ) );
		$slug = trim( preg_replace( '/-{2,}/', '-', $slug ), '-' );
		if ( strlen( $slug ) > 80 ) {
			$slug = trim( substr( $slug, 0, 80 ), '-' );
		}
		if ( '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			/* translators: %s: the unusable text the model returned */
			return new WP_Error( 'bad_slug', sprintf( __( 'The model returned something unusable as a slug: %s', 'ilang-readable-slugs' ), $text ) );
		}
		return $slug;
	}

	// ------------------------------------------------------------------ 设置页

	public static function menu() {
		add_options_page(
			__( 'iLang Readable Slugs', 'ilang-readable-slugs' ),
			__( 'Readable Slugs', 'ilang-readable-slugs' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ilang-readable-slugs' ) );
		}
		check_admin_referer( 'aislug_save' );
		$old = self::opts();

		$base_in = isset( $_POST['api_base'] ) ? sanitize_text_field( wp_unslash( $_POST['api_base'] ) ) : '';
		$base    = '' !== $base_in ? esc_url_raw( trim( $base_in ), array( 'https' ) ) : $old['api_base'];
		if ( '' === $base || 0 !== strpos( $base, 'https://' ) ) {
			$base = 'https://api.siliconflow.cn'; // 密钥走请求头,只允许 https
		}

		/*
		 * 模型:下拉选预置项,或选"自定义"后填 model id。
		 * 下拉给的是可读名称,自己填的才是 id —— 小白全程不需要知道 id 存在。
		 */
		$picked = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		if ( '__custom__' === $picked ) {
			$custom = isset( $_POST['model_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['model_custom'] ) ) : '';
			$model  = trim( $custom );
		} else {
			$model = trim( $picked );
		}
		if ( '' === $model || ! preg_match( '#^[\w.\-]+(/[\w.\-]+)*$#', $model ) ) {
			$model = $old['model']; // 空或不像 model id:保持原值,不让错值把功能弄哑
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
		wp_safe_redirect( add_query_arg( 'saved', 1, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ilang-readable-slugs' ) );
		}
		check_admin_referer( 'aislug_test' );
		$sample = self::sample_title();
		$result = self::generate( $sample );
		$q      = is_wp_error( $result )
			? array( 'test_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'test_ok' => rawurlencode( $result ) );
		wp_safe_redirect( add_query_arg( $q, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * "测试连接"用的样例标题。用站点自己的语言更有说服力 ——
	 * 中文站看到中文标题变成英文 slug,一眼就明白这插件干什么。
	 */
	private static function sample_title() {
		$samples = array(
			'zh' => '春节回家高铁抢票的十个实用技巧',
			'ja' => '新幹線のチケットを安く買う十の方法',
			'ko' => '설 연휴 기차표 예매하는 열 가지 방법',
			'ru' => 'Десять способов купить билеты на поезд дешевле',
			'ar' => 'عشر طرق لحجز تذاكر القطار بسعر أقل',
		);
		$short = strtolower( substr( get_locale(), 0, 2 ) );
		return isset( $samples[ $short ] ) ? $samples[ $short ] : '春节回家高铁抢票的十个实用技巧';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ilang-readable-slugs' ) );
		}
		$opts       = self::opts();
		$configured = '' !== trim( (string) $opts['api_key'] );
		$last_error = get_option( self::OPTION . '_last_error' );
		$in_backoff = (bool) get_transient( self::OPTION . '_backoff' );
		$presets    = self::models();
		$is_preset  = array_key_exists( $opts['model'], $presets );

		// 以下三个查询参数只是本插件自身 redirect 回来的状态提示,不改变任何数据,故不需要 nonce。
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice_saved = isset( $_GET['saved'] );
		$notice_ok    = isset( $_GET['test_ok'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['test_ok'] ) ) ) : '';
		$notice_err   = isset( $_GET['test_error'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 选"自定义"时才展开 model id 输入框。注册一个空句柄挂内联脚本,
		// 比在页面里裸写 <script> 干净,也过得了官方 Plugin Check。
		wp_register_script( 'aislug-admin', false, array(), '1.1.0', true );
		wp_enqueue_script( 'aislug-admin' );
		wp_add_inline_script(
			'aislug-admin',
			'document.addEventListener("DOMContentLoaded",function(){'
			. 'var s=document.getElementById("aislug-model"),c=document.getElementById("aislug-model-custom");'
			. 'if(!s||!c){return;}'
			. 'function t(){c.style.display=(s.value==="__custom__")?"":"none";if(s.value==="__custom__"){c.focus();}}'
			. 's.addEventListener("change",t);t();});'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'iLang Readable Slugs', 'ilang-readable-slugs' ); ?></h1>

			<?php if ( $notice_saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ilang-readable-slugs' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $notice_ok ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						printf(
							/* translators: 1: sample post title, 2: generated slug */
							esc_html__( 'Connection works. Sample title %1$s became %2$s', 'ilang-readable-slugs' ),
							'<em>' . esc_html( self::sample_title() ) . '</em>',
							'<code>' . esc_html( $notice_ok ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $notice_err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $notice_err ); ?></p></div>
			<?php endif; ?>
			<?php if ( $in_backoff ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The last attempt failed, so generation is paused for up to 10 minutes. Saving settings resumes it immediately.', 'ilang-readable-slugs' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $configured ) : ?>
				<?php // 首次进来的空状态:只讲"下一步做什么",不堆功能说明 ?>
				<div class="notice notice-info" style="padding:12px 16px">
					<h2 style="margin-top:0"><?php esc_html_e( 'One step to get started', 'ilang-readable-slugs' ); ?></h2>
					<p style="max-width:46em">
						<?php
						printf(
							/* translators: %s: link to sign up with the default provider */
							esc_html__( 'Paste an API key below and save. Any OpenAI-compatible provider works — if you do not have one yet, %s. The free credit alone covers a long time of normal use (well under a cent per slug).', 'ilang-readable-slugs' ),
							'<a href="https://cloud.siliconflow.cn/i/tJXyk0DQ" target="_blank" rel="noopener">' . esc_html__( 'sign up at SiliconFlow', 'ilang-readable-slugs' ) . '</a>'
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'That link is the author\'s referral link: signing up through it gives bonus credit to both you and this project. A key from anywhere else works exactly the same.', 'ilang-readable-slugs' ); ?></p>
				</div>
			<?php else : ?>
				<p class="description" style="max-width:52em">
					<?php esc_html_e( 'Post titles that are not written in English get a readable English slug when you save them. Titles already in English, slugs you typed yourself, and already-published posts are never touched. If generation fails, WordPress just does what it normally would — publishing is never blocked.', 'ilang-readable-slugs' ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aislug_save' ); ?>
				<input type="hidden" name="action" value="aislug_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'API key', 'ilang-readable-slugs' ); ?></th>
						<td>
							<input type="password" name="api_key" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $configured ? __( 'Saved — leave blank to keep', 'ilang-readable-slugs' ) : 'sk-...' ); ?>">
							<?php if ( $configured ) : ?>
								<p class="description"><?php esc_html_e( 'Leave blank to keep the key you already saved.', 'ilang-readable-slugs' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aislug-model"><?php esc_html_e( 'Model', 'ilang-readable-slugs' ); ?></label></th>
						<td>
							<select id="aislug-model" name="model">
								<?php foreach ( $presets as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $is_preset && $opts['model'] === $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
								<option value="__custom__" <?php selected( ! $is_preset ); ?>><?php esc_html_e( 'Custom model ID…', 'ilang-readable-slugs' ); ?></option>
							</select>
							<input type="text" id="aislug-model-custom" name="model_custom" class="regular-text"
								style="display:none;margin-left:8px" placeholder="provider/model-name"
								value="<?php echo esc_attr( $is_preset ? '' : $opts['model'] ); ?>">
							<p class="description"><?php esc_html_e( 'The default is a good choice for this job. Pick "Custom model ID" only if you know which model you want.', 'ilang-readable-slugs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aislug-context"><?php esc_html_e( 'Site context', 'ilang-readable-slugs' ); ?></label>
							<span class="description" style="font-weight:400"><?php esc_html_e( '(optional)', 'ilang-readable-slugs' ); ?></span></th>
						<td>
							<textarea id="aislug-context" name="site_context" class="large-text" rows="2"
								placeholder="<?php esc_attr_e( 'e.g. Camera review blog; brands that come up often: canon, sony, dji', 'ilang-readable-slugs' ); ?>"><?php echo esc_textarea( $opts['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Naming your topic and the brands you write about noticeably improves how proper nouns are spelled in slugs.', 'ilang-readable-slugs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Generation', 'ilang-readable-slugs' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $opts['enabled'] ); ?>>
								<?php esc_html_e( 'Generate slugs automatically when a post is saved', 'ilang-readable-slugs' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Advanced', 'ilang-readable-slugs' ); ?></th>
						<td>
							<details>
								<summary style="cursor:pointer"><?php esc_html_e( 'Use a different provider', 'ilang-readable-slugs' ); ?></summary>
								<p style="margin-top:10px">
									<label for="aislug-base"><?php esc_html_e( 'API base URL', 'ilang-readable-slugs' ); ?></label><br>
									<input type="url" id="aislug-base" name="api_base" class="regular-text" value="<?php echo esc_attr( $opts['api_base'] ); ?>">
								</p>
								<p class="description"><?php esc_html_e( 'Any OpenAI-compatible endpoint. HTTPS only. Leave this alone unless you are switching providers.', 'ilang-readable-slugs' ); ?></p>
							</details>
						</td>
					</tr>
				</table>
				<p>
					<button class="button button-primary"><?php esc_html_e( 'Save settings', 'ilang-readable-slugs' ); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px">
				<?php wp_nonce_field( 'aislug_test' ); ?>
				<input type="hidden" name="action" value="aislug_test">
				<button class="button" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Test connection', 'ilang-readable-slugs' ); ?></button>
				<?php if ( ! $configured ) : ?>
					<span class="description" style="margin-left:8px"><?php esc_html_e( 'Save an API key first.', 'ilang-readable-slugs' ); ?></span>
				<?php endif; ?>
			</form>

			<?php if ( $last_error ) : ?>
				<p class="description" style="margin-top:16px">
					<?php esc_html_e( 'Last failure:', 'ilang-readable-slugs' ); ?>
					<code><?php echo esc_html( $last_error ); ?></code>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}

AISlug_Plugin::init();
