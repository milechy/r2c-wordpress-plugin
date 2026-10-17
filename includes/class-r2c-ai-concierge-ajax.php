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
		add_action( 'wp_ajax_r2c_ai_concierge_resend_invite', array( __CLASS__, 'handle_resend_invite' ) );
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

	/**
	 * 手入力欄の値から接続キーを取り出す。`data-api-key="..."`(シングル
	 * クォートも可)を含む文字列なら、その属性値だけを返す。含まなければ
	 * 入力をそのまま(前後の空白だけ除いて)返す — 素のキーは従来どおり。
	 * 検証は一切ここでは行わない(呼び出し側が sanitize・R2C への確認を行う)。
	 */
	private static function extract_api_key( $raw ) {
		if ( preg_match( '/data-api-key\s*=\s*(["\'])(.*?)\1/is', $raw, $matches ) ) {
			return trim( $matches[2] );
		}
		return trim( $raw );
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

		// R2C 側の一時的な不調(5xx・HTML のエラーページ)。ネットワークの不調とは
		// 原因が違い、利用者の側でできることも無いので、文言を分ける。終端にはしない。
		if ( ! $result['ok'] && null !== $result['status'] && $result['status'] >= 500 ) {
			wp_send_json_success(
				array(
					'status'  => 'pending',
					'message' => __( 'R2C is having a temporary problem. We will keep trying. Please wait a moment.', 'r2c-ai-concierge' ),
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
				// 接続した時点で R2C に保存されている位置・色を取り込む。これをしないと、
				// 既存テナントの位置が、設定画面を次に開くまでサイトに出ない。取得に失敗
				// しても接続自体は成功のまま(次に設定画面を開いたときに取り込まれる)。
				$settings = R2C_AI_Concierge_Api_Client::get_settings( $body['api_key'] );
				if ( $settings['ok'] ) {
					R2C_AI_Concierge_Options::cache_theme_from_settings( $settings['body'] );
				}
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
					'message' => __( 'The connection finished, but the connection key could not be received. Please open "I already have an R2C contract" below and paste the connection key issued in your R2C dashboard.', 'r2c-ai-concierge' ),
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

		// 同一ドメインに確定済みテナントが既にある(要件書 X-3 / I-3)。
		//
		// ★2026-09-10 追加★ サーバ側は以前、申告(POST)の時点で 409 を返していたが、
		// この経路は未認証で誰でも任意のドメインを投げられるため、409 と 201 の差だけで
		// WordPress 顧客のドメイン一覧が列挙できた。よって申告では接続済み・未接続を
		// 問わず同一の 201 を返し、already_connected は**サイト所有証明(challenge の
		// 設置)を通したこのポーリングでのみ**開示する仕様に変わった。
		//
		// この分岐が無いと未知の status として下の pending に落ち、「待機中」のまま
		// 永久にポーリングし続けて利用者に何も伝わらない。終端状態なので
		// issued_without_key と同じく手動キー貼り付け(FR-05)へ案内する。
		// tenant_id はサーバが返さない(所有者にも返す理由が無い)。
		if ( 'already_connected' === $status ) {
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			R2C_AI_Concierge_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'already_connected',
					'message' => __( 'This site is already connected to R2C. Please open "I already have an R2C contract" below and paste the connection key issued in your R2C dashboard.', 'r2c-ai-concierge' ),
				)
			);
		}

		// pending のまま、こちら側の challenge(サイト所有証明の合言葉)が消えている。
		// WP 側は15分で消すが、R2C は最大24時間ポーリングに答え続けるため、放置すると
		// R2C が /verify を読めず 404 になり続け、「Basic認証を解除してください」と
		// 誤案内したまま終わらない。ここで終端にして、最初からやり直してもらう。
		if ( ! R2C_AI_Concierge_Options::get_pending_challenge() ) {
			R2C_AI_Concierge_Options::clear_pending_poll_token();
			wp_send_json_success(
				array(
					'status'  => 'failed',
					'message' => __( 'The time to confirm your site has run out. Please press "Connect" again.', 'r2c-ai-concierge' ),
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

		// R2C の管理画面は接続キーを script タグごとコピーさせるため、手入力欄に
		// タグ丸ごと貼られることが多い。sanitize_text_field() はタグを除去して
		// 中身を空にしてしまうので、その前に data-api-key の値だけを取り出す。
		// 取り出した値も、素のキーと同じ sanitize・同じ以降の検証(R2C への
		// 問い合わせ)を通す。
		$api_key = sanitize_text_field( self::extract_api_key( self::post_string( 'api_key' ) ) );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a connection key.', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_AI_Concierge_Api_Client::get_settings( $api_key );

		if ( ! $result['ok'] ) {
			if ( 401 === $result['status'] ) {
				wp_send_json_error( array( 'message' => __( 'This connection key is invalid.', 'r2c-ai-concierge' ) ) );
			}
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( 'Failed to verify the connection key.', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_AI_Concierge_Options::set_connection( $api_key, $body['tenant_id'], home_url( '/' ) );
		R2C_AI_Concierge_Options::cache_theme_from_settings( $body );

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
		R2C_AI_Concierge_Options::cache_theme_from_settings( $body );

		wp_send_json_success( array( 'settings' => $body ) );
	}

	/* 招待メールの再送(WP-UX-09) */

	public static function handle_resend_invite() {
		self::guard();

		if ( ! R2C_AI_Concierge_Options::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Not connected.', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_AI_Concierge_Api_Client::resend_invite( R2C_AI_Concierge_Options::get_api_key() );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => self::resend_invite_error_message( $result ) ) );
		}

		// 宛先は R2C が伏せ字にして返す(ta***@example.com)。画面には textContent で出す。
		$masked = ( is_array( $result['body'] ) && ! empty( $result['body']['email_masked'] ) && is_string( $result['body']['email_masked'] ) )
			? sanitize_text_field( $result['body']['email_masked'] )
			: '';

		if ( '' === $masked ) {
			$message = __( 'We sent an email with the link to the R2C dashboard to the address you entered when you connected. If it does not arrive, please also check your spam folder.', 'r2c-ai-concierge' );
		} else {
			/* translators: %s: the recipient's email address with part of it hidden, e.g. ta***@example.com */
			$message = sprintf( __( 'We sent an email with the link to the R2C dashboard to %s. If it does not arrive, please also check your spam folder.', 'r2c-ai-concierge' ), $masked );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * R2C のエラーコードごとに、次の行動つきの文言にする。サーバ文言は日本語
	 * 固定のため使わず、ここで翻訳可能な文言にする(コードは R2C 側の契約)。
	 */
	private static function resend_invite_error_message( $result ) {
		if ( null === $result['status'] ) {
			return self::unreachable_message();
		}
		$code = ( is_array( $result['body'] ) && isset( $result['body']['error'] ) && is_string( $result['body']['error'] ) ) ? $result['body']['error'] : '';

		if ( 429 === $result['status'] || 'rate_limit_exceeded' === $code ) {
			return __( 'An email was just sent. Please wait about an hour and try again.', 'r2c-ai-concierge' );
		}
		if ( 404 === $result['status'] || 'invite_recipient_not_found' === $code ) {
			return __( 'We could not find an address to send to. If you connected with a connection key, please log in to the R2C dashboard with your usual account. Otherwise, please contact R2C support.', 'r2c-ai-concierge' );
		}
		if ( 502 === $result['status'] || 'invite_send_failed' === $code ) {
			return __( 'The email could not be sent. Please try again in about an hour. If it still does not arrive, please contact R2C support.', 'r2c-ai-concierge' );
		}
		if ( 401 === $result['status'] ) {
			return __( 'The connection is no longer valid. Please reload this page and reconnect.', 'r2c-ai-concierge' );
		}
		return __( 'The email could not be sent right now. Please try again in a moment.', 'r2c-ai-concierge' );
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
