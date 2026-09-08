<?php
/**
 * Nonce-protected admin-ajax handlers behind the settings screen. This is
 * where every remote call this plugin makes actually originates from —
 * always as the direct result of an admin clicking a button on the R2C
 * settings page they navigated to, never from a hook that runs on its own.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_AI_Concierge_Ajax {

	const NONCE_ACTION = 'r2c_ai_concierge_admin_action';

	public static function init() {
		add_action( 'wp_ajax_r2c_ai_concierge_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'wp_ajax_r2c_ai_concierge_poll', array( __CLASS__, 'handle_poll' ) );
		add_action( 'wp_ajax_r2c_ai_concierge_connect_manual', array( __CLASS__, 'handle_connect_manual' ) );
		add_action( 'wp_ajax_r2c_ai_concierge_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'wp_ajax_r2c_ai_concierge_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Every handler starts here. Dies (via check_ajax_referer /
	 * wp_send_json_error) on failure, so callers can assume both checks
	 * passed once this returns.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'r2c-ai-concierge' ) ), 403 );
		}
	}

	private static function unreachable_message() {
		return __( 'Unable to reach R2C right now. Please try again in a moment.', 'r2c-ai-concierge' );
	}

	/**
	 * $_POST[$key] as an unslashed string, or '' if absent or not actually
	 * a string (e.g. a hand-crafted `key[]=x` array submission).
	 * sanitize_text_field()/sanitize_textarea_field() already guard against
	 * non-scalar input inside WordPress core, but sanitize_email() and
	 * sanitize_hex_color() do not — passing either an array ends up calling
	 * strlen()/preg_match() on it, which is a fatal TypeError on PHP 8+.
	 * Every raw $_POST read in this class goes through here instead of
	 * trusting the shape of the incoming request field-by-field.
	 */
	private static function post_string( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller reaches this only after guard() has verified the nonce.
			return '';
		}
		return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deliberately returns the raw unslashed value; every call site applies the sanitizer appropriate to that specific field (sanitize_email, sanitize_hex_color, sanitize_text_field, ...).
	}

	/* 接続開始 */

	public static function handle_connect() {
		self::guard();

		// FR-02: サーバ側の同意バックストップ。JSがチェック無しでボタンを
		// 押させない作りだが、それとは別にサーバ側でも確認する。
		$consent = '1' === self::post_string( 'consent' );
		if ( ! $consent ) {
			wp_send_json_error( array( 'message' => __( 'Please check the consent checkbox.', 'r2c-ai-concierge' ) ) );
		}

		$email = sanitize_email( self::post_string( 'email' ) );
		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'r2c-ai-concierge' ) ) );
		}

		// サイトURLは入力させない — このWPサイト自身のURLで確定させる
		// (admin以外がここに任意のドメインを指定できても、R2C側のサイト
		// 所有証明が失敗するだけだが、そもそも選ばせる項目にしない)。
		$result = R2C_AI_Concierge_Api_Client::provision(
			home_url( '/' ),
			$email,
			get_bloginfo( 'name' ),
			get_bloginfo( 'version' ),
			R2C_AI_CONCIERGE_VERSION,
			get_locale()
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( 'Failed to start the connection.', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_AI_Concierge_Options::set_pending_poll_token( $body['poll_token'], $body['provisioning_expires_in_hours'] );
		R2C_AI_Concierge_Options::set_pending_challenge( $body['challenge'], $body['challenge_expires_in_minutes'] );

		wp_send_json_success( array( 'waiting' => true ) );
	}

	/* ポーリング */

	public static function handle_poll() {
		self::guard();

		$poll_token = R2C_AI_Concierge_Options::get_pending_poll_token();
		if ( empty( $poll_token ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'No connection attempt is in progress. Please start over.', 'r2c-ai-concierge' ),
					'terminal' => true,
				)
			);
		}

		$result = R2C_AI_Concierge_Api_Client::poll( $poll_token );

		// トークンが見つからない(サーバ側で期限切れ掃除された等) → こちら側も
		// 未確定状態を片付けて、最初からやり直させる。
		if ( ! $result['ok'] && 404 === $result['status'] ) {
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
			wp_send_json_error(
				array(
					'message'  => __( 'The connection attempt could not be found. Please start over.', 'r2c-ai-concierge' ),
					'terminal' => true,
				)
			);
		}

		// 到達不能はまだ終端にしない — ネットワークの一時的な不調かもしれず、
		// JS側は次のポーリングを続ける(要件書 NFR-06 と同じ「サイトを壊さない」方針)。
		if ( ! $result['ok'] ) {
			wp_send_json_success(
				array(
					'status'  => 'pending',
					'message' => self::unreachable_message(),
				)
			);
		}

		$body   = $result['body'];
		$status = isset( $body['status'] ) ? $body['status'] : '';

		if ( 'provisioned' === $status ) {
			if ( ! empty( $body['api_key'] ) ) {
				R2C_AI_Concierge_Options::set_connection( $body['api_key'], $body['tenant_id'], home_url( '/' ) );
				R2C_AI_Concierge_Options::clear_pending_poll_token();
				R2C_AI_Concierge_Options::clear_pending_challenge();
				wp_send_json_success( array( 'status' => 'connected' ) );
			}
			// 発行は完了しているが平文キーを受け取れなかった(再ポーリング等の
			// 取りこぼし)。R2C_AI_Concierge_Options::is_connected() はまだ false のままなので、
			// 手動キー貼り付け(FR-05)へ案内する — これがこの穴の安全網。
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'issued_without_key',
					'message' => __( 'The connection finished, but the API key could not be received. Please paste the key issued in your R2C dashboard using "Enter API key manually" below.', 'r2c-ai-concierge' ),
				)
			);
		}

		if ( 'expired' === $status ) {
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'expired',
					'message' => __( 'Site verification expired before it could complete. Please try again.', 'r2c-ai-concierge' ),
				)
			);
		}

		if ( 'failed' === $status ) {
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'failed',
					'message' => self::failure_reason_message( isset( $body['reason'] ) ? $body['reason'] : '' ),
				)
			);
		}

		// pending。verify_reason / wait_reason があれば、それに応じた
		// 状況説明を出す(要件書 I-8 / I-9: 「なぜ待っているか」を具体的に)。
		wp_send_json_success(
			array(
				'status'  => 'pending',
				'message' => self::pending_reason_message( $body ),
			)
		);
	}

	/* 手動キー貼り付け(FR-05) */

	public static function handle_connect_manual() {
		self::guard();

		$api_key = sanitize_text_field( self::post_string( 'api_key' ) );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter an API key.', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_AI_Concierge_Api_Client::get_settings( $api_key );

		if ( ! $result['ok'] ) {
			if ( 401 === $result['status'] ) {
				wp_send_json_error( array( 'message' => __( 'This API key is invalid.', 'r2c-ai-concierge' ) ) );
			}
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( 'Failed to verify the API key.', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_AI_Concierge_Options::set_connection( $api_key, $body['tenant_id'], home_url( '/' ) );
		R2C_AI_Concierge_Options::set_cached_theme(
			isset( $body['position'] ) ? $body['position'] : null,
			isset( $body['offset_x'] ) ? $body['offset_x'] : null,
			isset( $body['offset_y'] ) ? $body['offset_y'] : null,
			isset( $body['primary_color'] ) ? $body['primary_color'] : null
		);

		wp_send_json_success( array( 'status' => 'connected' ) );
	}

	/* 解除 */

	public static function handle_disconnect() {
		self::guard();

		$api_key = R2C_AI_Concierge_Options::get_api_key();
		$warning = null;

		if ( ! empty( $api_key ) ) {
			$result = R2C_AI_Concierge_Api_Client::disconnect( $api_key );
			if ( ! $result['ok'] ) {
				// ★ローカルの資格情報削除は、R2C側の失効の成否とは切り離す★
				// FR-07の「ローカル資格情報を削除」は利用者が今すぐ止められる
				// べき操作。R2C側への失効リクエストが届かなくても、ここで
				// 止めるのを諦めない代わりに、その旨を明示する。
				$warning = __( 'The local connection info was removed, but revoking the key on R2C\'s side may have failed. If this is unexpected, please check the key status in your R2C dashboard.', 'r2c-ai-concierge' );
			}
		}

		R2C_AI_Concierge_Options::clear_connection();

		wp_send_json_success(
			array(
				'status'  => 'disconnected',
				'warning' => $warning,
			)
		);
	}

	/* ウィジェット表示設定の保存 */

	public static function handle_save_settings() {
		self::guard();

		if ( ! R2C_AI_Concierge_Options::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Not connected.', 'r2c-ai-concierge' ) ) );
		}

		$fields = array();

		if ( isset( $_POST['position'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer.
			$position = sanitize_text_field( self::post_string( 'position' ) );
			if ( in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
				$fields['position'] = $position;
			}
		}
		foreach ( array( 'offset_x', 'offset_y' ) as $key ) {
			$raw_offset = self::post_string( $key );
			if ( '' !== $raw_offset ) {
				$fields[ $key ] = absint( $raw_offset );
			}
		}
		$raw_color = self::post_string( 'primary_color' );
		if ( '' !== $raw_color ) {
			$color = sanitize_hex_color( $raw_color );
			if ( $color ) {
				$fields['primary_color'] = $color;
			}
		}
		// FR-10: テキストエリアの1行1パターンをそのまま配列化する。空文字列の
		// 送信(全パターン削除)も有効な操作として扱うため、issetのみで判定する
		// (他フィールドの「空なら無視」とは違う——ここは「空=クリア」が意味を持つ)。
		if ( isset( $_POST['excluded_page_patterns'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw   = sanitize_textarea_field( self::post_string( 'excluded_page_patterns' ) );
			$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ), 'strlen' );

			$fields['excluded_page_patterns'] = array_values( array_unique( $lines ) );
		}

		if ( empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'There is nothing to change.', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_AI_Concierge_Api_Client::patch_settings( R2C_AI_Concierge_Options::get_api_key(), $fields );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( 'Failed to save the settings.', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_AI_Concierge_Options::set_cached_theme(
			isset( $body['position'] ) ? $body['position'] : null,
			isset( $body['offset_x'] ) ? $body['offset_x'] : null,
			isset( $body['offset_y'] ) ? $body['offset_y'] : null,
			isset( $body['primary_color'] ) ? $body['primary_color'] : null
		);

		wp_send_json_success( array( 'settings' => $body ) );
	}

	/* 文言の組み立て */

	private static function error_message_for( $result, $fallback ) {
		if ( null === $result['status'] ) {
			return self::unreachable_message();
		}
		if ( is_array( $result['body'] ) && ! empty( $result['body']['message'] ) ) {
			return (string) $result['body']['message'];
		}
		return $fallback;
	}

	/**
	 * サイト所有証明の失敗理由(verify_reason)を利用者向けの日本語に変換する。
	 * 理由コードは src/api/widget/wpSiteVerifier.ts の WpVerifyFailure と一致させる。
	 */
	private static function pending_reason_message( $body ) {
		if ( ! empty( $body['wait_reason'] ) ) {
			if ( 'capacity_reached' === $body['wait_reason'] ) {
				return __( 'New sign-ups to R2C are currently at capacity. Please wait a moment.', 'r2c-ai-concierge' );
			}
			if ( 'daily_limit_reached' === $body['wait_reason'] ) {
				return __( 'Today\'s sign-up limit has been reached. Please try again tomorrow.', 'r2c-ai-concierge' );
			}
		}
		if ( ! empty( $body['verify_reason'] ) ) {
			switch ( $body['verify_reason'] ) {
				case 'http_error':
					return __( 'Verification access to this site was denied. Please temporarily disable Basic Auth or any access restrictions.', 'r2c-ai-concierge' );
				case 'blocked':
				case 'unreachable':
					return __( 'This site cannot be reached from outside. It must be a publicly accessible site (local environments cannot connect).', 'r2c-ai-concierge' );
				case 'invalid_body':
				case 'challenge_mismatch':
					return __( 'Site verification failed. Please make sure the plugin is activated.', 'r2c-ai-concierge' );
			}
		}
		return __( 'Verifying your site…', 'r2c-ai-concierge' );
	}

	private static function failure_reason_message( $reason ) {
		if ( 'site_unreachable' === $reason ) {
			return __( 'Site verification failed. Please try again.', 'r2c-ai-concierge' );
		}
		return __( 'Connection failed. Please try again.', 'r2c-ai-concierge' );
	}
}
