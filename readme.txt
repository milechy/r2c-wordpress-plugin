=== R2C – AI Concierge & Chat for Customer Support ===
Contributors: hkobayashi
Tags: chatbot, live chat, ai, customer support, faq
Requires at least: 6.0
Tested up to: 7.1
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
does not store or process your visitors' conversations. Day-to-day
operation — registering FAQ content, reviewing conversations, managing
your plan — happens in the R2C dashboard, linked from this plugin's
settings screen once connected.

Source code: https://github.com/milechy/r2c-wordpress-plugin

= External Services =

This plugin communicates with `api.r2c.biz`, the R2C API, to connect your
site, read and save widget display settings, and load the chat widget
script. No request is sent until you explicitly connect the plugin from
the settings screen.

* **Connecting**: sends your email address, your site's URL, name, and
  language, and your WordPress/plugin versions, so R2C can create or match
  your account
  and verify you control this site.
* **Opening the settings screen** (once connected): fetches your widget's
  current display settings — position, offset, brand color, and the list
  of pages/post types where the widget is hidden — from R2C, so the
  screen never shows stale values.
* **Saving a change**: sends only the fields you changed back to R2C.
* **Every front-end page view** (once connected): loads
  `https://api.r2c.biz/widget/{your-tenant-id}.js`, R2C's own script that
  renders the chat bubble and generates AI responses to your visitors'
  questions. That script's traffic goes to R2C's own AI providers
  (OpenAI, Groq, and Google Gemini) and, for the optional voice/avatar
  feature, LiveKit — not to this plugin.

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

= Where do I manage my FAQ content, or view conversations? =

In the R2C dashboard, linked from this plugin's settings screen once
you're connected. This plugin only handles installing and configuring
the widget on your WordPress site.

= What happens to the widget if I deactivate the plugin? =

It stops appearing on your site immediately. Your R2C account, FAQ
content, and conversation history are unaffected — reactivating the
plugin restores the widget without reconnecting.

= What happens if I uninstall (delete) the plugin? =

All locally stored settings (your API key, cached display settings) are
removed from this site. Your R2C account and its data are not deleted;
disconnect first from the settings screen if you also want to revoke the
API key on R2C's side immediately.

== Changelog ==

= 0.1.0 =
* Connect a WordPress site to R2C (new or existing account) with explicit consent, or by pasting an existing API key.
* Embed the R2C chat widget on the front end, with position, offset, and brand color synced from R2C.
* Hide the widget on specific pages, by post type, or by URL pattern.
* Disconnect at any time; revokes the API key on both sides.
