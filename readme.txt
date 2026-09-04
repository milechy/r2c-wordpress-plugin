=== R2C – AI Concierge & Chat for Customer Support ===
Contributors: milechy
Tags: chatbot, live chat, ai, customer support, faq
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed R2C's AI sales concierge widget on your site in one click.

== Description ==

R2C is an AI concierge that talks with your site's visitors, answers
product questions from your own FAQ knowledge base, and helps them
through checkout. This plugin adds a one-line embed of the R2C widget to
every page of your WordPress site, with no theme editing required.

**This plugin is a thin client for the R2C service.** Settings you change
here are sent to your R2C account and applied there; the plugin itself
does not store or process your visitors' conversations.

= External Services =

This plugin communicates with `api.r2c.biz`, the R2C API, to connect your
site, sync widget settings, and load the chat widget script. No request is
sent until you explicitly connect the plugin from the settings screen.

Data sent when you connect: your email address, your site's URL and name,
and your WordPress/plugin versions. Once connected, widget settings
(display position, excluded pages) are synced on save.

* R2C Terms of Service: https://r2c.biz/legal/terms.html
* R2C Privacy Policy: https://r2c.biz/legal/privacy.html

== Installation ==

1. Upload the plugin to your site, or install it from the WordPress
   plugin directory.
2. Activate the plugin.
3. Go to Settings → R2C and connect your site.

== Frequently Asked Questions ==

= Does this plugin work without a paid R2C account? =

Yes. R2C offers a free plan; connecting from this plugin creates one
automatically if you don't already have an R2C account.

== Changelog ==

= 0.1.0 =
* Initial scaffold. No functionality is wired up yet.
