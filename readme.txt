=== AI Slug - 中文标题智能英文链接 ===
Contributors: adsorgcn
Tags: slug, permalink, chinese, ai, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

发文时用 AI 自动把中文标题翻译成精炼的英文 URL slug,告别百分号乱码和生硬拼音。

== Description ==

WordPress 默认把中文标题编码成百分号乱码链接,拼音插件生成的 slug 又长又没有 SEO 价值。AI Slug 在你保存文章时,把中文标题交给大模型翻译成 3-6 个词的英文 slug——地名、品牌、意图都翻准,达到人类编辑水准。

= 特性 =

* 生成失败自动回退 WordPress 默认行为,绝不阻塞发文
* 纯英文标题、手工指定过 slug、已发布的旧文,一概不动
* 自动保存不触发、低权限用户不触发、全站每小时限额
* 站点背景/品牌词表可定制,模型可任选
* 支持任何 OpenAI 兼容接口(默认硅基流动)

= 使用前提 =

需要一个 OpenAI 兼容服务的 API Key(如硅基流动)。文章标题会发送到你配置的 API 服务商用于生成 slug,不涉及正文和其他数据。

== Installation ==

1. 上传插件并启用
2. 在 设置 → AI Slug 填入 API Key,点"测试连接"
3. 发布中文标题的文章即可看到自动生成的英文 slug

== Frequently Asked Questions ==

= 费用多少? =

默认模型每个 slug 约 100 token,折合不到 0.001 元人民币。

= 旧文章的链接会变吗? =

不会。已发布文章的 slug 永远不动。

== Changelog ==

= 1.0.0 =
* 首个公开版本:AI 生成 slug、失败回退与退避、防滥用限额、站点背景定制、测试连接
