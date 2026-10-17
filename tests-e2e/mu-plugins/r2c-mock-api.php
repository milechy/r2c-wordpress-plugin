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

	const HITS_OPTION = 'r2c_mock_hits';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'count_hit' ), 10, 3 );
	}

	/**
	 * プラグインが R2C に話しかけた回数(GL#7「未接続ゼロ通信」のE2E用)。
	 * /__test__/ 配下(テスト自身の操作)は数えない。
	 */
	public static function count_hit( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 === strpos( $route, '/r2c-mock/' ) && false === strpos( $route, '/__test__/' ) ) {
			update_option( self::HITS_OPTION, (int) get_option( self::HITS_OPTION, 0 ) + 1, false );
		}
		return $result;
	}

	public static function handle_test_hits() {
		return new WP_REST_Response( array( 'hits' => (int) get_option( self::HITS_OPTION, 0 ) ), 200 );
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
			// WP-17/D13: GET /v1/public/wp/status のモック応答。'settings' とは
			// 独立した別エンドポイントの状態(D9のとおり、この画面から書き込む
			// 値は一切含まない)。
			'status' => array(
				'plan'                   => 'starter',
				'avatar_active'          => false,
				'faq_published_count'    => 0,
				'open_escalations_count' => 0,
				'weekly_summary'         => array(
					'sessions_this_week' => 0,
					'learned_this_week'  => 0,
				),
			),
			'controls' => array(
				'poll_attempts_until_provisioned' => 1,
				'forced_poll_status'              => null, // null | 'expired' | 'failed' | 'server_error'(500)
				// true: ポーリングの応答前に、WP 側の challenge(15分で消える合言葉)を消す。
				'clear_challenge_on_poll'         => false,
				'force_settings_down'              => false,
				// POST /v1/public/wp/resend-invite の結果(WP-UX-09)。
				// null=成功 | 'rate_limit'(429) | 'no_recipient'(404) | 'send_failed'(502)
				'resend_invite_result'             => null,
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
			'/v1/public/wp/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_status' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/v1/public/wp/resend-invite',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_resend_invite' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'r2c-mock',
			'/__test__/hits',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_test_hits' ),
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

		if ( ! empty( $state['controls']['clear_challenge_on_poll'] ) && class_exists( 'R2C_AI_Concierge_Options' ) ) {
			R2C_AI_Concierge_Options::clear_pending_challenge();
		}

		$forced = $state['controls']['forced_poll_status'];
		if ( 'server_error' === $forced ) {
			return new WP_REST_Response( array( 'error' => 'internal_error' ), 500 );
		}
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

	public static function handle_get_status( WP_REST_Request $req ) {
		$state = self::get_state();
		if ( $state['controls']['force_settings_down'] ) {
			return new WP_REST_Response( array( 'error' => 'service_unavailable', 'message' => 'mocked outage' ), 503 );
		}

		$api_key = $req->get_header( 'x-api-key' );
		if ( ! in_array( $api_key, $state['active_api_keys'], true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_api_key' ), 401 );
		}

		return new WP_REST_Response( $state['status'], 200 );
	}

	/**
	 * サーバ側 PR(commerce-faq-tasks#1758)の契約どおりの応答を返す:
	 * 200 {message,email_masked} / 401 / 404 invite_recipient_not_found /
	 * 429 rate_limit_exceeded / 502 invite_send_failed。
	 * 文言はプラグインが error コードから自前で組み立てるので、ここの message は
	 * わざと画面に出ない別文言にしてある(サーバ文言をそのまま出していないことの確認)。
	 */
	public static function handle_resend_invite( WP_REST_Request $req ) {
		$state   = self::get_state();
		$api_key = $req->get_header( 'x-api-key' );
		if ( ! in_array( $api_key, $state['active_api_keys'], true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_api_key', 'message' => 'mock-server-text' ), 401 );
		}

		$result = $state['controls']['resend_invite_result'];
		if ( 'rate_limit' === $result ) {
			return new WP_REST_Response( array( 'error' => 'rate_limit_exceeded', 'message' => 'mock-server-text' ), 429 );
		}
		if ( 'no_recipient' === $result ) {
			return new WP_REST_Response( array( 'error' => 'invite_recipient_not_found', 'message' => 'mock-server-text' ), 404 );
		}
		if ( 'send_failed' === $result ) {
			return new WP_REST_Response( array( 'error' => 'invite_send_failed', 'message' => 'mock-server-text' ), 502 );
		}
		return new WP_REST_Response( array( 'message' => 'mock-server-text', 'email_masked' => 'ow***@example.com' ), 200 );
	}

	/* ---- テスト制御専用(本物のAPIには存在しない) ---- */

	/**
	 * モック側(このAPIが保持する仮想テナントの状態)だけでなく、この
	 * WordPressインストール自身がローカルに持つ接続状態(r2c_ai_concierge_api_key等)も
	 * 一緒に消す。テストは全て同一のWordPressインストールを共有するため、
	 * ここでR2C_AI_Concierge_Optionsをクリアしておかないと、直前のテストで接続済みに
	 * なった状態が次のテストへそのまま残り、次のテストの
	 * gotoSettings()が(未接続用の)接続フォームではなく接続済み画面を
	 * 表示してしまい、summary/manual-key要素待ちが延々とタイムアウトする
	 * (実機のCIログで確認した実際の不具合)。
	 */
	public static function handle_test_reset() {
		self::save_state( self::default_state() );
		delete_option( self::HITS_OPTION );
		if ( class_exists( 'R2C_AI_Concierge_Options' ) ) {
			R2C_AI_Concierge_Options::clear_connection();
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * body例:
	 *   { "poll_attempts_until_provisioned": 0 }  即座にprovisionedにする
	 *   { "forced_poll_status": "expired" }       毎回expiredを返す("server_error" なら 500)
	 *   { "clear_challenge_on_poll": true }       ポーリングのたびに WP 側の challenge を消す(15分経過の再現)
	 *   { "site_origin": "https://old.example" } 接続時のサイトのアドレスとして保存されている値を差し替える(ドメイン変更・複製の再現)
	 *   { "force_settings_down": true }           settings/disconnectを503に落とす
	 *   { "resend_invite_result": "rate_limit" }  招待再送の応答を 429 に(no_recipient=404 / send_failed=502)
	 *   { "seed_api_key": "mock_xxx" }            手動キー貼り付けテスト用に有効キーを事前登録
	 *   { "revoke_api_key": "mock_xxx" }          そのキーを失効させる(以後の呼び出しは401)
	 *   { "status": { "avatar_active": true } }   GET /v1/public/wp/status の応答を部分上書き
	 */
	public static function handle_test_configure( WP_REST_Request $req ) {
		$state = self::get_state();
		$body  = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_body' ), 400 );
		}

		foreach ( array( 'poll_attempts_until_provisioned', 'forced_poll_status', 'clear_challenge_on_poll', 'force_settings_down', 'resend_invite_result' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$state['controls'][ $key ] = $body[ $key ];
			}
		}
		if ( ! empty( $body['site_origin'] ) && class_exists( 'R2C_AI_Concierge_Options' ) ) {
			update_option( R2C_AI_Concierge_Options::SITE_ORIGIN, (string) $body['site_origin'], false );
		}
		if ( ! empty( $body['seed_api_key'] ) ) {
			$state['active_api_keys'][] = $body['seed_api_key'];
		}
		if ( ! empty( $body['revoke_api_key'] ) ) {
			$state['active_api_keys'] = array_values( array_diff( $state['active_api_keys'], array( $body['revoke_api_key'] ) ) );
		}
		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$state['settings'] = array_merge( $state['settings'], $body['settings'] );
		}
		if ( isset( $body['status'] ) && is_array( $body['status'] ) ) {
			$state['status'] = array_merge( $state['status'], $body['status'] );
		}

		self::save_state( $state );
		return new WP_REST_Response( array( 'ok' => true, 'state' => $state ), 200 );
	}
}

R2C_Mock_Api::init();
