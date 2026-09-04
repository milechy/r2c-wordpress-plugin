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
 * Skeleton only (WP-6). The full plugin (settings screen, provisioning,
 * widget output, verify endpoint) lands in follow-up commits — see
 * docs/WORDPRESS_PLUGIN_REQUIREMENTS.md in the milechy/commerce-faq-tasks
 * repository for the requirements this plugin implements (WP-6..WP-9).
 *
 * ★No functionality is wired up yet on purpose★
 * This file intentionally does nothing beyond defining constants and
 * loading the translation catalogue. Guideline #7 (wordpress.org Detailed
 * Plugin Guidelines) requires that a plugin contact no external server
 * without explicit, authorized consent — so until the connect flow
 * (WP-7) exists, this plugin must not perform any network request, and it
 * doesn't.
 */

define( 'R2C_AI_CONCIERGE_VERSION', '0.1.0' );
define( 'R2C_AI_CONCIERGE_FILE', __FILE__ );
define( 'R2C_AI_CONCIERGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'R2C_AI_CONCIERGE_URL', plugin_dir_url( __FILE__ ) );

// api.r2c.biz is the only host this plugin will ever talk to (WP-1/2/3/13,
// commerce-faq-tasks). Keeping it as a single constant means there is one
// place to audit for "which external service does this plugin call".
define( 'R2C_AI_CONCIERGE_API_BASE', 'https://api.r2c.biz' );

/**
 * Load the r2c-ai-concierge text domain.
 *
 * All UI strings ship in English; Japanese (the primary market per the
 * requirements doc) is provided via the .mo/.po files in /languages, not
 * via hardcoded strings switched on WPLANG (see docs §11.4 / NFR-07).
 */
function r2c_ai_concierge_load_textdomain() {
	load_plugin_textdomain( 'r2c-ai-concierge', false, dirname( plugin_basename( R2C_AI_CONCIERGE_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'r2c_ai_concierge_load_textdomain' );

/**
 * Remove everything this plugin created. Wired up for real once WP-7
 * starts writing options (D-loop: uninstall.php mirrors this file 1:1 so
 * "what did we create" is answered by grepping both files together).
 */
function r2c_ai_concierge_activate() {
	// Intentionally empty until WP-7 introduces the first option to register.
}
register_activation_hook( R2C_AI_CONCIERGE_FILE, 'r2c_ai_concierge_activate' );
