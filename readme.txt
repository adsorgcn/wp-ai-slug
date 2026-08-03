=== iLang Readable Slugs ===
Contributors: eastsoft
Tags: slug, permalink, seo, translation, ai
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Automatically turn non-English post titles into clean, readable English URL slugs using AI.

== Description ==

When a post title is written in Chinese, Japanese, Korean, Arabic, Russian or any other non-Latin script, WordPress falls back to percent-encoded slugs such as `%e6%96%af%e5%b7%b4...`. Those URLs are unreadable, hard to share, and carry no SEO value.

iLang Readable Slugs fixes that.

What it does, in I-Lang (https://ilang.ai, the AI-native protocol this plugin family is named after):

`[READ:@SRC|path=title]=>[XLAT|lng=en]=>[SHRT|len=short]=>[OUT]`

Read the title, translate the meaning, condense it, ship it. That pipeline is the whole plugin. When you save a post, the title is sent to an AI model that returns a concise 3-6 word English slug capturing the meaning of the title, the way a human editor would write it. Place names, brand names and intent are all translated correctly, unlike romanization plugins that transliterate character by character.

Example results:

* A Chinese review of a San Jose VPS becomes `sparta-vps-san-jose-network-speed-test`
* A Chinese guide to appealing a banned ChatGPT account becomes `chatgpt-account-ban-appeal-guide`

= Features =

* Falls back to the WordPress default silently if generation fails, so publishing is never blocked
* Skips titles already in English, slugs you set by hand, and already-published posts
* Does not run on autosave, does not run for low-privilege users, and enforces an hourly site-wide cap
* Optional site context field teaches the model your niche and brand names
* Works with any OpenAI-compatible chat completion endpoint

= External service =

This plugin requires an API key for an OpenAI-compatible AI service. By default it calls SiliconFlow (https://siliconflow.cn), and you may point it at any other compatible provider in the settings.

What is sent, and when: only the post title is transmitted, and only when you save a post whose title contains non-ASCII characters and has no usable slug yet. Post content, user data and site data are never sent.

SiliconFlow terms of service: https://docs.siliconflow.cn/en/legals/terms-of-service
SiliconFlow privacy policy: https://docs.siliconflow.cn/en/legals/privacy-policy

The author's SiliconFlow referral link is shown on the settings screen. Signing up through it grants bonus credits to both the new user and the author. Using it is entirely optional: an API key from any compatible provider works the same way.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings > Readable Slugs and enter your API key.
3. Click "Test connection" to verify.
4. Publish a post with a non-English title and the slug is generated automatically.

== Frequently Asked Questions ==

= How much does it cost? =

Each slug uses roughly 100 tokens with the default model, which costs a fraction of a cent. Free signup credits typically last for years of normal blogging.

= Will the links of my existing posts change? =

No. Slugs of already-published posts are never modified. Only new posts and drafts get a generated slug.

= Can I use a provider other than the default? =

Yes. Any OpenAI-compatible /v1/chat/completions endpoint works. Change the API base URL and model ID in the settings. Only HTTPS endpoints are accepted.

= Does it work with the REST API and automated publishing? =

Yes. Programmatic publishing goes through the same filter. If your request explicitly supplies a slug, that value is respected.

== Changelog ==

= 1.0.0 =
* Initial public release: AI slug generation, silent fallback and back-off, abuse limits, site context option, connection test.
