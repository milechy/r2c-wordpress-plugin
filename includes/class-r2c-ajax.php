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

class R2C_Ajax {

	const NONCE_ACTION = 'r2c_admin_action';

	public static function init() {
		add_action( 'wp_ajax_r2c_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'wp_ajax_r2c_poll', array( __CLASS__, 'handle_poll' ) );
		add_action( 'wp_ajax_r2c_connect_manual', array( __CLASS__, 'handle_connect_manual' ) );
		add_action( 'wp_ajax_r2c_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'wp_ajax_r2c_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Every handler starts here. Dies (via check_ajax_referer /
	 * wp_send_json_error) on failure, so callers can assume both checks
	 * passed once this returns.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'この操作を行う権限がありません。', 'r2c-ai-concierge' ) ), 403 );
		}
	}

	private static function unreachable_message() {
		return __( '現在 R2C に接続できません。しばらくしてから再度お試しください。', 'r2c-ai-concierge' );
	}

	/* 接続開始 */

	public static function handle_connect() {
		self::guard();

		// FR-02: サーバ側の同意バックストップ。JSがチェック無しでボタンを
		// 押させない作りだが、それとは別にサーバ側でも確認する。
		$consent = isset( $_POST['consent'] ) && '1' === $_POST['consent']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer.
		if ( ! $consent ) {
			wp_send_json_error( array( 'message' => __( '同意チェックボックスにチェックを入れてください。', 'r2c-ai-concierge' ) ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( '有効なメールアドレスを入力してください。', 'r2c-ai-concierge' ) ) );
		}

		// サイトURLは入力させない — このWPサイト自身のURLで確定させる
		// (admin以外がここに任意のドメインを指定できても、R2C側のサイト
		// 所有証明が失敗するだけだが、そもそも選ばせる項目にしない)。
		$result = R2C_Api_Client::provision(
			home_url( '/' ),
			$email,
			get_bloginfo( 'name' ),
			get_bloginfo( 'version' ),
			R2C_AI_CONCIERGE_VERSION,
			get_locale()
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( '接続の開始に失敗しました。', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_Options::set_pending_poll_token( $body['poll_token'], $body['provisioning_expires_in_hours'] );
		R2C_Options::set_pending_challenge( $body['challenge'], $body['challenge_expires_in_minutes'] );

		wp_send_json_success( array( 'waiting' => true ) );
	}

	/* ポーリング */

	public static function handle_poll() {
		self::guard();

		$poll_token = R2C_Options::get_pending_poll_token();
		if ( empty( $poll_token ) ) {
			wp_send_json_error(
				array(
					'message'  => __( '進行中の接続試行がありません。最初からやり直してください。', 'r2c-ai-concierge' ),
					'terminal' => true,
				)
			);
		}

		$result = R2C_Api_Client::poll( $poll_token );

		// トークンが見つからない(サーバ側で期限切れ掃除された等) → こちら側も
		// 未確定状態を片付けて、最初からやり直させる。
		if ( ! $result['ok'] && 404 === $result['status'] ) {
			R2C_Options::clear_pending_poll_token();
			R2C_Options::clear_pending_challenge();
			wp_send_json_error(
				array(
					'message'  => __( '接続試行が見つかりませんでした。最初からやり直してください。', 'r2c-ai-concierge' ),
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
				R2C_Options::set_connection( $body['api_key'], $body['tenant_id'], home_url( '/' ) );
				R2C_Options::clear_pending_poll_token();
				R2C_Options::clear_pending_challenge();
				wp_send_json_success( array( 'status' => 'connected' ) );
			}
			// 発行は完了しているが平文キーを受け取れなかった(再ポーリング等の
			// 取りこぼし)。R2C_Options::is_connected() はまだ false のままなので、
			// 手動キー貼り付け(FR-05)へ案内する — これがこの穴の安全網。
			R2C_Options::clear_pending_poll_token();
			R2C_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'issued_without_key',
					'message' => __( '接続処理は完了しましたが、APIキーを受信できませんでした。下の「APIキーを直接入力」からR2C管理画面で発行したキーを貼り付けてください。', 'r2c-ai-concierge' ),
				)
			);
		}

		if ( 'expired' === $status ) {
			R2C_Options::clear_pending_poll_token();
			R2C_Options::clear_pending_challenge();
			wp_send_json_success(
				array(
					'status'  => 'expired',
					'message' => __( 'サイトの確認が完了しないまま期限切れになりました。もう一度お試しください。', 'r2c-ai-concierge' ),
				)
			);
		}

		if ( 'failed' === $status ) {
			R2C_Options::clear_pending_poll_token();
			R2C_Options::clear_pending_challenge();
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

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'APIキーを入力してください。', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_Api_Client::get_settings( $api_key );

		if ( ! $result['ok'] ) {
			if ( 401 === $result['status'] ) {
				wp_send_json_error( array( 'message' => __( 'APIキーが無効です。', 'r2c-ai-concierge' ) ) );
			}
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( 'APIキーの確認に失敗しました。', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_Options::set_connection( $api_key, $body['tenant_id'], home_url( '/' ) );
		R2C_Options::set_cached_theme(
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

		$api_key = R2C_Options::get_api_key();
		$warning = null;

		if ( ! empty( $api_key ) ) {
			$result = R2C_Api_Client::disconnect( $api_key );
			if ( ! $result['ok'] ) {
				// ★ローカルの資格情報削除は、R2C側の失効の成否とは切り離す★
				// FR-07の「ローカル資格情報を削除」は利用者が今すぐ止められる
				// べき操作。R2C側への失効リクエストが届かなくても、ここで
				// 止めるのを諦めない代わりに、その旨を明示する。
				$warning = __( 'ローカルの接続情報は削除しましたが、R2C側でのキー失効に失敗した可能性があります。心当たりがない場合はR2Cの管理画面でキーの状態をご確認ください。', 'r2c-ai-concierge' );
			}
		}

		R2C_Options::clear_connection();

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

		if ( ! R2C_Options::is_connected() ) {
			wp_send_json_error( array( 'message' => __( '接続されていません。', 'r2c-ai-concierge' ) ) );
		}

		$fields = array();

		if ( isset( $_POST['position'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$position = sanitize_text_field( wp_unslash( $_POST['position'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
				$fields['position'] = $position;
			}
		}
		foreach ( array( 'offset_x', 'offset_y' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$fields[ $key ] = absint( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		if ( isset( $_POST['primary_color'] ) && '' !== $_POST['primary_color'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$color = sanitize_hex_color( wp_unslash( $_POST['primary_color'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $color ) {
				$fields['primary_color'] = $color;
			}
		}

		if ( empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( '変更する項目がありません。', 'r2c-ai-concierge' ) ) );
		}

		$result = R2C_Api_Client::patch_settings( R2C_Options::get_api_key(), $fields );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => self::error_message_for( $result, __( '設定の保存に失敗しました。', 'r2c-ai-concierge' ) ) ) );
		}

		$body = $result['body'];
		R2C_Options::set_cached_theme(
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
				return __( '現在R2Cへの新規登録が混み合っています。しばらくお待ちください。', 'r2c-ai-concierge' );
			}
			if ( 'daily_limit_reached' === $body['wait_reason'] ) {
				return __( '本日の新規登録上限に達しました。明日改めてお試しください。', 'r2c-ai-concierge' );
			}
		}
		if ( ! empty( $body['verify_reason'] ) ) {
			switch ( $body['verify_reason'] ) {
				case 'http_error':
					return __( 'このサイトへの確認アクセスが拒否されました。Basic認証やアクセス制限を一時的に解除してください。', 'r2c-ai-concierge' );
				case 'blocked':
				case 'unreachable':
					return __( 'このサイトに外部からアクセスできません。公開されているサイトである必要があります(ローカル環境等では接続できません)。', 'r2c-ai-concierge' );
				case 'invalid_body':
				case 'challenge_mismatch':
					return __( 'サイトの確認に失敗しました。プラグインが有効化されているか確認してください。', 'r2c-ai-concierge' );
			}
		}
		return __( 'サイトを確認しています…', 'r2c-ai-concierge' );
	}

	private static function failure_reason_message( $reason ) {
		if ( 'site_unreachable' === $reason ) {
			return __( 'サイトの確認に失敗しました。もう一度お試しください。', 'r2c-ai-concierge' );
		}
		return __( '接続に失敗しました。もう一度お試しください。', 'r2c-ai-concierge' );
	}
}
