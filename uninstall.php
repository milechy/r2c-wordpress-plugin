<?php
/**
 * Uninstall handler for R2C – AI Concierge & Chat for Customer Support.
 *
 * WordPress runs this file (not the main plugin file) when the plugin is
 * deleted from the Plugins screen. It must remove every option this plugin
 * ever creates. Kept in sync with R2C_Options (includes/class-r2c-options.php)
 * — when a new option/transient constant is added there, add its removal
 * here in the same commit.
 *
 * ★Deleting the plugin does not delete the R2C tenant or its conversation
 * data★ — only local WordPress state. See R2C_Ajax::handle_disconnect /
 * FR-07 in the requirements doc for the same guarantee on the in-app
 * "disconnect" action.
 *
 * @package R2C_AI_Concierge
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options + transients (options.php is loaded here, but plugin classes are
// not — WordPress runs uninstall.php standalone, not through the plugin's
// normal bootstrap). Delete raw option names directly rather than
// requiring class-r2c-options.php, so this file has no dependency on the
// rest of the plugin's code still being loadable at uninstall time.
delete_option( 'r2c_api_key' );
delete_option( 'r2c_tenant_id' );
delete_option( 'r2c_site_origin' );
delete_option( 'r2c_cached_widget_theme' );
delete_option( 'r2c_connect_notice_dismissed' );
delete_transient( 'r2c_pending_poll_token' );
delete_transient( 'r2c_pending_challenge' );
