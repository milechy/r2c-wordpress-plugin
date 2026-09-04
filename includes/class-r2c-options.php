<?php
/**
 * Local storage for this plugin. Thin wrapper around wp_options so every
 * other class reads/writes through named methods instead of raw option
 * strings scattered across the codebase.
 *
 * ★What this class does NOT hold★
 * Widget placement (position/offset/color) and page-exclusion rules are
 * NOT authoritative here — R2C's DB is (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md
 * D9). `cached_widget_theme` below is a *read-through cache* for rendering
 * the front-end <script> tag without a remote call on every pageview; it is
 * refreshed every time the settings screen successfully talks to R2C and is
 * never treated as the value to submit back. This mirrors the 5-minute
 * cache R2C's own widget.js delivery already has (requirements FR-09) —
 * staleness here is the same accepted trade-off, not a new one.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_Options {

	const API_KEY           = 'r2c_api_key';
	const TENANT_ID         = 'r2c_tenant_id';
	const SITE_ORIGIN       = 'r2c_site_origin';
	const CACHED_THEME      = 'r2c_cached_widget_theme';
	const PENDING_POLL      = 'r2c_pending_poll_token';
	const PENDING_CHALLENGE = 'r2c_pending_challenge';
	const NOTICE_DISMISSED  = 'r2c_connect_notice_dismissed';

	/**
	 * True once a connection exists locally. Does not confirm the key is
	 * still valid on R2C's side — callers that need that must actually
	 * call the API (e.g. the settings screen's GET on render).
	 */
	public static function is_connected() {
		return (bool) self::get_api_key();
	}

	public static function get_api_key() {
		return get_option( self::API_KEY, '' );
	}

	public static function get_tenant_id() {
		return get_option( self::TENANT_ID, '' );
	}

	public static function get_site_origin() {
		return get_option( self::SITE_ORIGIN, '' );
	}

	/**
	 * Persist a successful connection. `autoload = false` for the key and
	 * tenant id (NFR-05: not part of the on-every-request alloptions blob;
	 * these are only read from the admin settings screen and the
	 * front-end widget-output hook, not on every request type).
	 */
	public static function set_connection( $api_key, $tenant_id, $site_origin ) {
		update_option( self::API_KEY, (string) $api_key, false );
		update_option( self::TENANT_ID, (string) $tenant_id, false );
		update_option( self::SITE_ORIGIN, (string) $site_origin, false );
	}

	/**
	 * Remove everything a connection created. Called on disconnect AND
	 * from uninstall.php — keep both call sites in sync when this list
	 * changes.
	 */
	public static function clear_connection() {
		delete_option( self::API_KEY );
		delete_option( self::TENANT_ID );
		delete_option( self::SITE_ORIGIN );
		delete_option( self::CACHED_THEME );
	}

	/**
	 * Cache of GET/PATCH /v1/public/wp/settings' theme fields, read on
	 * every front-end pageview to build the <script> tag's data-* attrs.
	 * `autoload = true` (the default) is intentional here — it's read on
	 * every front-end request, so keeping it out of the alloptions cache
	 * would add a query per pageview instead of saving one.
	 */
	public static function get_cached_theme() {
		$theme = get_option( self::CACHED_THEME, array() );
		return is_array( $theme ) ? $theme : array();
	}

	public static function set_cached_theme( $position, $offset_x, $offset_y, $primary_color ) {
		update_option(
			self::CACHED_THEME,
			array(
				'position'      => $position,
				'offset_x'      => $offset_x,
				'offset_y'      => $offset_y,
				'primary_color' => $primary_color,
			)
		);
	}

	/**
	 * The in-flight provisioning attempt (between POST /provision and a
	 * successful poll). Short-lived by design — a transient, not an
	 * option, so a stalled attempt cleans itself up without needing a
	 * cron job (禁止30の多重起動は要らない、cronそのものが不要な設計).
	 */
	public static function get_pending_poll_token() {
		return get_transient( self::PENDING_POLL );
	}

	public static function set_pending_poll_token( $poll_token, $expires_in_hours ) {
		set_transient( self::PENDING_POLL, (string) $poll_token, max( 1, (int) $expires_in_hours ) * HOUR_IN_SECONDS );
	}

	public static function clear_pending_poll_token() {
		delete_transient( self::PENDING_POLL );
	}

	/**
	 * The site-ownership challenge R2C expects to read back from
	 * `/wp-json/r2c/v1/verify` (R2C_Verify_Endpoint). Stored separately
	 * from the poll token — the two are different secrets with different
	 * directions of travel (this one WP serves outbound to R2C; the poll
	 * token WP sends to R2C). TTL matches `challenge_expires_in_minutes`
	 * from the provision response, not the (longer) poll-token TTL.
	 */
	public static function get_pending_challenge() {
		return get_transient( self::PENDING_CHALLENGE );
	}

	public static function set_pending_challenge( $challenge, $expires_in_minutes ) {
		set_transient( self::PENDING_CHALLENGE, (string) $challenge, max( 1, (int) $expires_in_minutes ) * MINUTE_IN_SECONDS );
	}

	public static function clear_pending_challenge() {
		delete_transient( self::PENDING_CHALLENGE );
	}

	public static function is_notice_dismissed() {
		return (bool) get_option( self::NOTICE_DISMISSED, false );
	}

	public static function dismiss_notice() {
		update_option( self::NOTICE_DISMISSED, true );
	}
}
