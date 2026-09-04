<?php
/**
 * The site-ownership proof R2C's server fetches during provisioning
 * (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md §5.2, src/api/widget/
 * wpSiteVerifier.ts in the parent repo). Registers a single public REST
 * route that returns whatever challenge this site is currently holding.
 *
 * ★Must stay unauthenticated★ R2C's server calls this with no credentials
 * (it can't — the WP site is the one being verified, it has no R2C
 * credential yet at this point). The security property here is not "who
 * is allowed to read this" but "this value only exists while a connection
 * attempt this WordPress admin explicitly started is pending" — see
 * R2C_Options::set_pending_challenge / clear_pending_challenge.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_Verify_Endpoint {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			'r2c/v1',
			'/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle( $request ) {
		$challenge = R2C_Options::get_pending_challenge();

		if ( empty( $challenge ) ) {
			// No connection attempt in flight (or it already expired) —
			// nothing to prove ownership of right now. 404, not an empty
			// 200, so R2C's verifier treats this the same as "plugin not
			// installed" rather than "installed but broken".
			return new WP_REST_Response( array( 'error' => 'no_pending_challenge' ), 404 );
		}

		return new WP_REST_Response( array( 'challenge' => $challenge ), 200 );
	}
}
