<?php
/**
 * Uninstall handler for R2C – AI Concierge & Chat for Customer Support.
 *
 * WordPress runs this file (not the main plugin file) when the plugin is
 * deleted from the Plugins screen. It must remove every option this plugin
 * ever creates. Kept 1:1 with what r2c-ai-concierge.php actually writes —
 * when WP-7 adds the first `add_option()` call, add its removal here in
 * the same commit.
 *
 * @package R2C_AI_Concierge
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally empty (skeleton, WP-6). No options exist yet to remove.
// Deleting this plugin today deletes zero site data.
