=== StealthGPT ===
Contributors: danilstealthgpt
Tags: ai, content, writing, humanize, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official StealthGPT plugin: generate and humanize natural, publish-ready content. Output is always saved to a draft for your review.

== Description ==

StealthGPT for WordPress connects your site to the StealthGPT API so you can create natural, human, publish-ready content without leaving the editor.

This is the official StealthGPT plugin, developed and maintained by XYZ AI LLC (the makers of StealthGPT) — not a third-party or community integration. The plugin is free and open source, released under the GPL. StealthGPT itself is a separate commercial service; the plugin is a client that connects to it using your own API token (see "External services" below).

Two modes are available:

* **Generate** — a full content pipeline (research, drafting, optional fact-check, optional images) from a prompt, with SEO, academic, and social presets.
* **Humanize** — turn existing text into natural, human-reading content.

Content is produced asynchronously: you start a run, and the result is written to a **draft** post when it finishes. The plugin never auto-publishes — you always review and publish yourself.

Completion is delivered via a signed webhook when your site is publicly reachable, and falls back to background polling (via Action Scheduler) otherwise, so it also works on private or local sites.

= Features =

* Meta box in the post/page editor and a dedicated "StealthGPT" admin page.
* Bring your own StealthGPT API token (entered on the settings page, validated against your account balance).
* Output is always saved as a draft for review.
* Markdown output is converted to editor-ready content.
* Secure: signed (HMAC-SHA256) webhook verification, capability checks, and nonces on all actions.

== External services ==

This plugin connects to the StealthGPT API (provided by XYZ AI LLC) to generate and humanize content. It is required for the plugin to function.

**What is sent and when:** When you start a generation or humanization run, the prompt and/or text you provide, along with your run options, are sent to StealthGPT over HTTPS using the API token you configure. To receive the completed result, the plugin may register a webhook URL on your site and/or poll the StealthGPT status endpoint for that run. Your API token is sent with each request for authentication.

* Service: StealthGPT — https://www.stealthgpt.ai
* Terms of Service: https://www.stealthgpt.ai/terms
* Privacy Policy: https://www.stealthgpt.ai/privacy

You need a StealthGPT account and API token to use this plugin. Get a token at https://www.stealthgpt.ai/stealthapi

== Installation ==

1. Upload the `stealthgpt` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **StealthGPT → Settings** and enter your StealthGPT API token. The plugin validates it against your account balance.
4. Use the **StealthGPT** meta box in the post editor, or the **StealthGPT → New content** page, to start a run. The result is saved to a draft.

== Frequently Asked Questions ==

= Does it publish automatically? =

No. Output is always saved as a draft. You review and publish it yourself.

= Do I need a StealthGPT account? =

Yes. You bring your own API token from https://www.stealthgpt.ai/stealthapi

= Will it work on a local or private site? =

Yes. Webhooks can only reach publicly accessible sites, so on local/private installs the plugin automatically falls back to background polling to retrieve results.

== Screenshots ==

1. The StealthGPT New content admin page: generate a full article from a prompt with SEO, academic, or social presets.
2. Humanize mode: paste text, start a run, and get a link to the finished draft when it completes.
3. The StealthGPT meta box in the post editor sidebar.
4. The settings page: bring your own API token, validated against your StealthGPT account balance.

== Changelog ==

= 1.0.2 =
* Fully fix the screenshot caption on the plugin directory page (the menu arrow character was breaking the image markup).

= 1.0.1 =
* Clarify in the readme that this is the official plugin from XYZ AI LLC and that the plugin (not the StealthGPT service) is the free, open-source part.
* Fix a broken screenshot caption on the plugin directory page.

= 1.0.0 =
* Initial release: generate and humanize content, BYO API token, draft output, signed webhook with polling fallback.
