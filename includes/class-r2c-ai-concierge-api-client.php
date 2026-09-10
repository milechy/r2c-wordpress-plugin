<?php
/**
 * The only place in this plugin that talks to api.r2c.biz.
 *
 * Every method returns the same shape so callers handle success/failure
 * uniformly instead of each writing its own wp_remote_* boilerplate:
 *   array(
 *     'ok'     => bool,          // true only for 2xx with valid JSON
 *     'status' => int|null,      // HTTP status, null on transport failure
 *     'body'   => array|null,    // decoded JSON body, null on transport
 *                                // failure or non-JSON response
 *     'error'  => string|null,   // WP_Error message on transport failure
 *   )
 *
 * ★No call in this file ever fires from a hook that runs unconditionally
 * (init, wp, admin_init, etc.)★ Every method here is invoked only from an
 * explicit, nonce-verified admin action (R2C_AI_Concierge_Ajax) or from rendering the
 * settings screen itself (which the admin navigated to on purpose). This is
 * what keeps "zero communication while disconnected" true — see
 * docs/WORDPRESS_PLUGIN_REQUIREMENTS.md §7 A-2 / GL#7 in the parent repo.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_AI_Concierge_Api_Client {

	const TIMEOUT_SECONDS = 10;

	/**
	 * POST /v1/public/wp/provision — start a new connection attempt.
	 */
	public static function provision( $site_url, $email, $site_name, $wp_version, $plugin_version, $locale ) {
		return self::request(
			'POST',
			'/v1/public/wp/provision',
			null,
			array(
				'site_url'       => $site_url,
				'email'          => $email,
				'site_name'      => $site_name,
				'wp_version'     => $wp_version,
				'plugin_version' => $plugin_version,
				'locale'         => $locale,
			)
		);
	}

	/**
	 * GET /v1/public/wp/provision/{poll_token} — check whether site
	 * verification + issuance completed.
	 */
	public static function poll( $poll_token ) {
		return self::request( 'GET', '/v1/public/wp/provision/' . rawurlencode( $poll_token ), null, null );
	}

	/**
	 * POST /v1/public/wp/disconnect — revoke the API key R2C-side.
	 * Local credential removal (R2C_AI_Concierge_Options::clear_connection) is a
	 * separate step the caller performs regardless of this call's outcome
	 * — see R2C_AI_Concierge_Ajax::handle_disconnect for the reasoning.
	 */
	public static function disconnect( $api_key ) {
		return self::request( 'POST', '/v1/public/wp/disconnect', $api_key, null );
	}

	/**
	 * GET /v1/public/wp/settings — current position/offset/color/
	 * excluded-pages/allowed-origins for the connected tenant.
	 */
	public static function get_settings( $api_key ) {
		return self::request( 'GET', '/v1/public/wp/settings', $api_key, null );
	}

	/**
	 * PATCH /v1/public/wp/settings — partial update. `$fields` uses the
	 * same snake_case keys as the API response (position, offset_x,
	 * offset_y, primary_color, excluded_page_patterns, allowed_origins).
	 */
	public static function patch_settings( $api_key, array $fields ) {
		return self::request( 'PATCH', '/v1/public/wp/settings', $api_key, $fields );
	}

	/**
	 * GET /v1/public/wp/status — read-only usage summary for the settings
	 * screen (WP-17/D13): this week's conversation/learning counts, plan,
	 * avatar state, FAQ count, unresolved inquiry count. Deliberately a
	 * separate call from get_settings() — this endpoint never returns
	 * anything the settings form writes back (D9/D10 stay untouched).
	 */
	public static function get_status( $api_key ) {
		return self::request( 'GET', '/v1/public/wp/status', $api_key, null );
	}

	/**
	 * Shared request builder every public method above funnels through.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $path     Path, leading slash, no host.
	 * @param string|null $api_key  x-api-key header value, or null for the
	 *                              two unauthenticated provisioning routes.
	 * @param array|null  $body     JSON body, or null for a bodyless request.
	 */
	private static function request( $method, $path, $api_key, $body ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		if ( null !== $api_key ) {
			$args['headers']['x-api-key'] = $api_key;
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( R2C_AI_CONCIERGE_API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => null,
				'body'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		// A non-JSON body (e.g. an nginx error page during an outage) must
		// not be treated as a valid API response with `body === null`
		// silently accepted downstream — surface it as a transport-shaped
		// failure so callers show the same "can't reach R2C" message they
		// would for a network error, rather than mis-parsing null as
		// "reachable but empty".
		if ( null === $parsed && JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'body'   => null,
				'error'  => 'invalid_json_response',
			);
		}

		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $parsed,
			'error'  => null,
		);
	}
}
