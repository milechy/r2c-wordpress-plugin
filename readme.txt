=== R2C – AI Concierge & Chat for Customer Support ===
Contributors: hkobayashi
Tags: chatbot, live chat, ai, customer support, faq
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
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
  language, and your WordPress/plugin versions, so R2C can create a new
  free account for this site and verify you control this site. R2C then
  emails an invitation (to set a password for the R2C dashboard) to that
  address. Connecting never matches an existing R2C account by email; if
  you already have an R2C contract, connect with a connection key issued
  in the R2C dashboard instead (see the FAQ below).
* **Opening the settings screen** (once connected): fetches your widget's
  current display settings — position, offset, brand color, and the list
  of pages/post types where the widget is hidden — from R2C, so the
  screen never shows stale values. Also fetches a read-only usage summary
  — this week's conversation count, things the AI learned this week,
  your current plan, avatar on/off status, published FAQ count, and
  unresolved inquiry count — to display on the same screen. The same
  display settings are also fetched once right after connecting. The
  position and color are kept on your site so the widget can be placed
  without a request on every page view.
* **Saving a change**: sends only the fields you changed back to R2C.
* **Resending the invitation email** (only when you press the button on
  the settings screen): asks R2C to email the dashboard link again. The
  plugin sends no address; R2C uses the one you entered when connecting,
  and allows this once per hour.
* **Every front-end page view** (once connected): loads
  `https://api.r2c.biz/widget/{your-tenant-id}.js`, R2C's own script that
  renders the chat bubble and generates AI responses to your visitors'
  questions. The script tag also carries your site's R2C widget API key
  (`data-api-key`), which the script sends to R2C to identify your site;
  this key is designed to be public and is visible in your page's HTML.
  That script's traffic goes to R2C's own AI providers
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

Yes. R2C offers a free plan. Connecting from the settings screen with the
"First time using R2C?" button always creates a new free account for this
site. It is not matched to an existing R2C account by email address.

= What are the conditions of the free plan? =

* Ads and R2C branding appear on the chat widget.
* The widget pauses after 200 conversations per month and starts again on
  the 1st of the next month.
* Conversation data is used for R2C's shared learning; this cannot be
  changed on the free plan.
* If you move to a paid plan and cancel it later, your site returns to the
  free plan and the widget keeps running with ads.

These are shown on the settings screen before you agree to connect. Plans
and pricing: https://r2c.biz/lp/

= I already have an R2C contract. How do I connect? =

Open Settings → R2C and choose "I already have an R2C contract". In the
R2C dashboard, tell the assistant "Issue a connection key for WordPress",
then paste the key it gives you. Please do not use the free "Connect"
button: it creates a second, separate free account.

= How do I log in to R2C after connecting? =

When you start with the free plan, R2C sends an invitation email to the
address you entered. Open it, set a password, then log in to the R2C
dashboard. If it does not arrive, check your spam folder.

= How do I change my plan or cancel? =

In the R2C dashboard, tell the assistant what you want (for example, "I
want to cancel"). Disconnecting this plugin does not cancel your contract
and does not stop billing.

= Where do I manage my FAQ content, or view conversations? =

In the R2C dashboard, linked from this plugin's settings screen once
you're connected. This plugin only handles installing and configuring
the widget on your WordPress site.

= What happens to the widget if I deactivate the plugin? =

It stops appearing on your site immediately. Your R2C account, FAQ
content, and conversation history are unaffected — reactivating the
plugin restores the widget without reconnecting.

= What happens if I uninstall (delete) the plugin? =

All locally stored settings (your connection key, cached display settings) are
removed from this site. Your R2C account and its data are not deleted, and
your contract is not cancelled; disconnect first from the settings screen
if you also want to revoke the connection key on R2C's side immediately.

== Changelog ==

= 0.1.1 =
* Fix: the chat widget could not answer questions because the API key was not passed to the widget script. The widget is now only shown when both the tenant ID and the API key are stored.

= 0.1.0 =
* Connect a WordPress site to R2C with explicit consent (creates a new free account), or by pasting a connection key from an existing R2C account.
* Embed the R2C chat widget on the front end, with position, offset, and brand color synced from R2C.
* Hide the widget on specific pages, by post type, or by URL pattern.
* Disconnect at any time; revokes the API key on both sides. Disconnecting does not cancel your R2C contract or stop billing.
