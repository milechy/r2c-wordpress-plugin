<?php
/**
 * Plugin Name:       R2C – AI Concierge & Chat for Customer Support
 * Plugin URI:        https://github.com/milechy/r2c-wordpress-plugin
 * Description:       Embed R2C's AI sales concierge widget on your site in one click. Connect an existing R2C account or create a free one from this screen.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            R2C
 * Author URI:        https://r2c.biz
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       r2c-ai-concierge
 * Domain Path:       /languages
 *
 * @package R2C_AI_Concierge
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * See docs/WORDPRESS_PLUGIN_REQUIREMENTS.md in the milechy/commerce-faq-tasks
 * repository for the requirements this plugin implements (WP-1..WP-15).
 *
 * ★No network request fires until the admin explicitly connects★
 * Every class that talks to R2C (R2C_Api_Client) is only ever invoked from
 * R2C_Ajax's nonce-verified handlers, which only run when an admin submits
 * the connect form or an already-connected settings action — never from a
 * hook that runs unconditionally (init, wp, admin_init). Guideline #7
 * (wordpress.org Detailed Plugin Guidelines) requires exactly this.
 */

define( 'R2C_AI_CONCIERGE_VERSION', '0.1.0' );
define( 'R2C_AI_CONCIERGE_FILE', __FILE__ );
define( 'R2C_AI_CONCIERGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'R2C_AI_CONCIERGE_URL', plugin_dir_url( __FILE__ ) );

// api.r2c.biz is the only host this plugin will ever talk to (WP-1/2/3/13,
// commerce-faq-tasks). Keeping it as a single constant means there is one
// place to audit for "which external service does this plugin call".
//
// The `if ( ! defined() )` guard is a standard WP convention allowing a
// must-use plugin (which loads first) to pre-define this — used only by
// tests-e2e/mu-plugins/r2c-api-base-override.php in the wp-env E2E
// environment to point at the local mock API instead of the real one
// (see tests-e2e/README.md). Never present in a real install: nothing
// outside this test fixture defines this constant, so production behavior
// is unchanged.
if ( ! defined( 'R2C_AI_CONCIERGE_API_BASE' ) ) {
	define( 'R2C_AI_CONCIERGE_API_BASE', 'https://api.r2c.biz' );
}

// UI strings ship in English; Japanese (the primary market per the
// requirements doc) is provided via languages/r2c-ai-concierge-ja.mo (see
// docs §11.4 / NFR-07). No load_plugin_textdomain() call here on purpose —
// since WP 4.6, core auto-loads a plugin's translations (including a
// bundled .mo matching this Text Domain in /languages) the moment a
// translation function is first called; calling it manually is discouraged
// (https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/)
// and flagged by Plugin Check.

require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-options.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-api-client.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-verify-endpoint.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-ajax.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-settings-page.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-notice.php';
require_once R2C_AI_CONCIERGE_DIR . 'includes/class-r2c-widget.php';

R2C_Verify_Endpoint::init();
R2C_Ajax::init();
R2C_Settings_Page::init();
R2C_Notice::init();
R2C_Widget::init();

/**
 * Nothing to do on activation — every option this plugin writes is created
 * lazily by the connect flow (R2C_Ajax::handle_connect /
 * handle_connect_manual), not up front. Kept as an explicit no-op (rather
 * than omitted) so a future option that genuinely needs activation-time
 * setup has an obvious place to go, and so uninstall.php's "nothing to
 * register here either" comment stays true by inspection.
 */
function r2c_ai_concierge_activate() {
	// Intentionally empty — see doc comment above.
}
register_activation_hook( R2C_AI_CONCIERGE_FILE, 'r2c_ai_concierge_activate' );
