<?php
/**
 * E2E専用のR2C APIモック。実際のapi.r2c.bizの代わりにこのWP自身のREST APIへ
 * ルートを生やし(r2c-api-base-override.phpがR2C_AI_CONCIERGE_API_BASEを
 * home_url('/wp-json/r2c-mock')へ差し替える)、プラグイン単体の動作だけを
 * 検証する。commerce-faq-tasks側の本物のサイト所有証明・課金・DB等は
 * 一切再現しない — それらは向こうのテストスイートの責任範囲(tests-e2e/
 * README.md参照)。
 *
 * 状態は wp_options の r2c_mock_state に保持し、テストごとに
 * POST /wp-json/r2c-mock/v1/__test__/reset で初期化する。
 *
 * @package R2C_AI_Concierge_E2E
 */

defined( 'ABSPATH' ) || exit;

class R2C_Mock_Api {

	const OPTION_KEY = 'r2c_mock_state';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function default_state() {
		return array(
			'provisioning' => null,
			'active_api_keys' => array(),
			'settings' => array(
				'tenant_id'              => 't_e2e',
				'plan'                   => 'starter',
				'is_active'              => true,
				'has_published_faq'      => false,
				'position'               => 'bottom-right',
				'offset_x'               => 24,
				'offset_y'               => 24,
				'primary_color'          => null,
				'excluded_page_patterns' => array(),
				'allowed_origins'        => array(),
			),
			'controls' => array(
				'poll_attempts_until_provisioned' => 1,
				'forced_poll_status'              => null, // null | 'expired' | 'failed'
				'force_settings_down'              => false,
			),
		);
	}

	private static function get_state() {
		$state = get_option( self::OPTION_KEY, null );
		return is_array( $state ) ? $state : self::default_state();
	}

	private static function save_state( $state ) {
		update_option( self::OPTION_KEY, $state, false );
	}

	public static function register_routes() {
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_provision' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/provision/(?P<token>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_poll' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_disconnect' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_settings' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/settings',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'handle_patch_settings' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/__test__/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_test_reset' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/__test__/configure',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_test_configure' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---- 本物のAPIを模した経路 ---- */

	public static function handle_provision( WP_REST_Request $req ) {
		$state = self::get_state();

		$poll_token = 'poll_' . wp_generate_password( 24, false );
		$challenge  = 'chal_' . wp_generate_password( 24, false );

		$state['provisioning'] = array(
			'poll_token'      => $poll_token,
			'polls_remaining' => max( 0, (int) $state['controls']['poll_attempts_until_provisioned'] ),
			'api_key'         => 'mock_' . wp_generate_password( 32, false ),
			'tenant_id'       => 't_e2e',
		);
		self::save_state( $state );

		return new WP_REST_Response(
			array(
				'poll_token'                     => $poll_token,
				'provisioning_expires_in_hours'  => 24,
				'challenge'                      => $challenge,
				'challenge_expires_in_minutes'   => 15,
			),
			200
		);
	}

	public static function handle_poll( WP_REST_Request $req ) {
		$state       = self::get_state();
		$provisioning = $state['provisioning'];

		if ( ! $provisioning || $provisioning['poll_token'] !== $req->get_param( 'token' ) ) {
			return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}

		$forced = $state['controls']['forced_poll_status'];
		if ( 'expired' === $forced || 'failed' === $forced ) {
			$body = array( 'status' => $forced );
			if ( 'failed' === $forced ) {
				$body['reason'] = 'site_unreachable';
			}
			return new WP_REST_Response( $body, 200 );
		}

		if ( $provisioning['polls_remaining'] > 0 ) {
			$provisioning['polls_remaining']--;
			$state['provisioning'] = $provisioning;
			self::save_state( $state );
			return new WP_REST_Response( array( 'status' => 'pending' ), 200 );
		}

		$state['active_api_keys'][] = $provisioning['api_key'];
		$state['settings']['tenant_id'] = $provisioning['tenant_id'];
		self::save_state( $state );

		return new WP_REST_Response(
			array(
				'status'    => 'provisioned',
				'api_key'   => $provisioning['api_key'],
				'tenant_id' => $provisioning['tenant_id'],
			),
			200
		);
	}

	public static function handle_disconnect( WP_REST_Request $req ) {
		$state = self::get_state();
		if ( $state['controls']['force_settings_down'] ) {
			return new WP_REST_Response( array( 'error' => 'service_unavailable' ), 503 );
		}

		$api_key = $req->get_header( 'x-api-key' );
		$state['active_api_keys'] = array_values( array_diff( $state['active_api_keys'], array( $api_key ) ) );
		self::save_state( $state );

		return new WP_REST_Response( array( 'status' => 'disconnected' ), 200 );
	}

	public static function handle_get_settings( WP_REST_Request $req ) {
		$state = self::get_state();
		if ( $state['controls']['force_settings_down'] ) {
			return new WP_REST_Response( array( 'error' => 'service_unavailable', 'message' => 'mocked outage' ), 503 );
		}

		$api_key = $req->get_header( 'x-api-key' );
		// TEMP DEBUG(WP-11 E2E調査中、原因特定後に削除)
		error_log( '[R2C_MOCK_DEBUG] get_settings header=' . var_export( $api_key, true ) . ' active_api_keys=' . var_export( $state['active_api_keys'], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( ! in_array( $api_key, $state['active_api_keys'], true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_api_key' ), 401 );
		}

		return new WP_REST_Response( $state['settings'], 200 );
	}

	public static function handle_patch_settings( WP_REST_Request $req ) {
		$state = self::get_state();
		if ( $state['controls']['force_settings_down'] ) {
			return new WP_REST_Response( array( 'error' => 'service_unavailable', 'message' => 'mocked outage' ), 503 );
		}

		$api_key = $req->get_header( 'x-api-key' );
		if ( ! in_array( $api_key, $state['active_api_keys'], true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_api_key' ), 401 );
		}

		$fields = $req->get_json_params();
		if ( is_array( $fields ) ) {
			foreach ( array( 'position', 'offset_x', 'offset_y', 'primary_color', 'excluded_page_patterns', 'allowed_origins' ) as $key ) {
				if ( array_key_exists( $key, $fields ) ) {
					$state['settings'][ $key ] = $fields[ $key ];
				}
			}
		}
		self::save_state( $state );

		return new WP_REST_Response( $state['settings'], 200 );
	}

	/* ---- テスト制御専用(本物のAPIには存在しない) ---- */

	/**
	 * モック側(このAPIが保持する仮想テナントの状態)だけでなく、この
	 * WordPressインストール自身がローカルに持つ接続状態(r2c_api_key等)も
	 * 一緒に消す。テストは全て同一のWordPressインストールを共有するため、
	 * ここでR2C_Optionsをクリアしておかないと、直前のテストで接続済みに
	 * なった状態が次のテストへそのまま残り、次のテストの
	 * gotoSettings()が(未接続用の)接続フォームではなく接続済み画面を
	 * 表示してしまい、summary/manual-key要素待ちが延々とタイムアウトする
	 * (実機のCIログで確認した実際の不具合)。
	 */
	public static function handle_test_reset() {
		self::save_state( self::default_state() );
		if ( class_exists( 'R2C_Options' ) ) {
			R2C_Options::clear_connection();
			R2C_Options::clear_pending_poll_token();
			R2C_Options::clear_pending_challenge();
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * body例:
	 *   { "poll_attempts_until_provisioned": 0 }  即座にprovisionedにする
	 *   { "forced_poll_status": "expired" }       毎回expiredを返す
	 *   { "force_settings_down": true }           settings/disconnectを503に落とす
	 *   { "seed_api_key": "mock_xxx" }            手動キー貼り付けテスト用に有効キーを事前登録
	 */
	public static function handle_test_configure( WP_REST_Request $req ) {
		$state = self::get_state();
		$body  = $req->get_json_params();
		// TEMP DEBUG(WP-11 E2E調査中、原因特定後に削除)
		error_log( '[R2C_MOCK_DEBUG] configure raw_body=' . $req->get_body() . ' parsed=' . var_export( $body, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}

		foreach ( array( 'poll_attempts_until_provisioned', 'forced_poll_status', 'force_settings_down' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$state['controls'][ $key ] = $body[ $key ];
			}
		}
		if ( ! empty( $body['seed_api_key'] ) ) {
			$state['active_api_keys'][] = $body['seed_api_key'];
		}
		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$state['settings'] = array_merge( $state['settings'], $body['settings'] );
		}

		self::save_state( $state );
		// TEMP DEBUG(WP-11 E2E調査中、原因特定後に削除)
		error_log( '[R2C_MOCK_DEBUG] configure saved active_api_keys=' . var_export( $state['active_api_keys'], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return new WP_REST_Response( array( 'ok' => true, 'state' => $state ), 200 );
	}
}

R2C_Mock_Api::init();
