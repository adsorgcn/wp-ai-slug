# AI Slug — 中文标题智能英文链接

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net/)

发文时自动把中文标题翻译成**精炼的英文 URL slug**。告别百分号乱码链接和生硬的逐字拼音。

## 效果

| 中文标题 | 生成的 slug |
|---|---|
| 斯巴达VPS圣何塞机房深度测评：三网直连值不值 | `sparta-vps-san-jose-network-speed-test` |
| 搬瓦工2026年最新优惠码与套餐整理 | `bandwagonhost-2026-promo-codes-plans` |
| RackNerd 黑五特价：年付10刀的洛杉矶VPS值得买吗 | `racknerd-black-friday-los-angeles-vps` |
| ChatGPT账号被封了怎么申诉解封 | `chatgpt-account-ban-appeal-guide` |

不是拼音（`sibada-vps-shenghesai-jifang...`），不是机翻长句，是人类编辑水准的 3-6 词 SEO slug——地名、品牌、意图全部翻准。

> 💡 **基本免费**：通过[邀请链接](https://cloud.siliconflow.cn/i/tJXyk0DQ)注册硅基流动，**注册双方都会获得赠送额度**；每个 slug 消耗不到 0.001 元，赠送额度就足够用很多年。

## 为什么

- WordPress 默认把中文标题编码成 `%e6%96%af%e5%b7%b4...` 这种谁都看不懂的链接
- 拼音类插件生成的 slug 又长又没有 SEO 价值（搜索引擎不懂拼音）
- 翻译 API 类插件把标题逐字翻成冗长句子，且百度/有道接口在境外服务器经常抽风

大模型干这件事又快又好，每个 slug 的成本不到一厘钱。

## 3 分钟上手

1. [下载最新版](../../releases) 并在 WP 后台 插件 → 上传安装,或将 `ai-slug.php` 放入 `wp-content/plugins/ai-slug/` 后启用
2. 注册 [硅基流动](https://cloud.siliconflow.cn/i/tJXyk0DQ) 拿 API Key（👆 通过邀请链接注册，**双方都会获得赠送额度，而且赠送的额度就足够本插件长期使用——相当于免费**；也支持任何 OpenAI 兼容接口）
3. WP 后台 → 设置 → AI Slug,粘贴 Key,点 **测试连接**
4. 完事。以后发中文标题的文章,slug 自动变英文

## 特性

- **零打扰**:生成失败自动回退 WordPress 默认行为,绝不阻塞发文;失败后自动退避 10 分钟
- **有分寸**:纯英文标题、手工指定过 slug、已发布的旧文,一概不动
- **防滥用**:自动保存不触发、低权限用户不触发、全站每小时限额,不怕脚本刷爆账单
- **可定制**:站点背景/品牌词表设置,让 AI 认识你圈子里的专有名词;模型可任选
- **REST 友好**:程序化发文(如自动化机器人)同样生效;显式传 slug 时尊重你的值

## FAQ

**费用多少?** 默认的 Qwen3.5-9B 模型每个 slug 消耗约 100 token,折合不到 0.001 元。硅基流动注册赠送的额度足够用很多年。

**我的数据会被发到哪里?** 只有**文章标题**会发送到你配置的 API 服务商用于生成 slug,不涉及正文和任何其他数据。

**能用别家的 API 吗?** 能。任何 OpenAI 兼容接口(`/v1/chat/completions`)都行,改"API 地址"和模型 ID 即可,仅限 https。

**旧文章的链接会变吗?** 不会。已发布文章的 slug 永远不动;只有新文章(和未发布的草稿)才会生成。

## 开发者

```php
// 修改触发所需的用户能力(默认 publish_posts)
add_filter( 'aislug_cap', fn() => 'edit_posts' );

// 修改每小时全站限额(默认 60)
add_filter( 'aislug_hourly_limit', fn() => 200 );

// 增加自定义文章类型(默认 post、page)
add_filter( 'aislug_post_types', fn( $types ) => array_merge( $types, array( 'product' ) ) );
```

## License

MIT。欢迎 PR。

---

*作者:[静水流深](https://github.com/adsorgcn) — 中国第一代跨境互联网建设者。搭积木,不造轮子。*
