<?php
/**
 * Loads the Japanese translation this plugin bundles in /languages.
 *
 * WordPress 6.8+ registers a plugin's "Domain Path" header by itself, so a
 * bundled .mo is found with no call from us. Below 6.8 (this plugin declares
 * 6.0 as its minimum) nothing looks in /languages, and the owner would see
 * English even on a Japanese site — hence this call, and only there. On
 * wordpress.org installs a language pack from translate.wordpress.org in
 * WP_LANG_DIR still wins, because load_plugin_textdomain() reads that first.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_AI_Concierge_I18n {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	public static function load_textdomain() {
		if ( version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ) {
			return;
		}
		load_plugin_textdomain( 'r2c-ai-concierge', false, dirname( plugin_basename( R2C_AI_CONCIERGE_FILE ) ) . '/languages' );
	}
}
