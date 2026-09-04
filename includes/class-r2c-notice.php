<?php
/**
 * The one site-wide admin notice this plugin is allowed to show
 * (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md FR-11 / GL#11): a single,
 * dismissible notice while unconnected. Nothing else — no upgrade nags,
 * no repeated notices after dismissal or connection.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_Notice {

	const DISMISS_ACTION = 'r2c_dismiss_notice';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	private static function should_show() {
		return current_user_can( 'manage_options' )
			&& ! R2C_Options::is_connected()
			&& ! R2C_Options::is_notice_dismissed()
			&& ! self::is_on_settings_page();
	}

	private static function is_on_settings_page() {
		$screen = get_current_screen();
		return $screen && 'settings_page_' . R2C_Settings_Page::SLUG === $screen->id;
	}

	/**
	 * このJSは通知が実際に出うる全管理画面で読み込む(設定画面専用の
	 * admin-settings.jsとは別。通知は設定画面以外にも出るため)。
	 */
	public static function maybe_enqueue() {
		if ( ! self::should_show() ) {
			return;
		}
		wp_enqueue_script(
			'r2c-admin-notice',
			R2C_AI_CONCIERGE_URL . 'assets/js/admin-notice.js',
			array(),
			R2C_AI_CONCIERGE_VERSION,
			true
		);
		wp_localize_script(
			'r2c-admin-notice',
			'r2cNotice',
			array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
		);
	}

	public static function maybe_render() {
		if ( ! self::should_show() ) {
			return;
		}

		// ★WP コアの `is-dismissible` は使わない★ その自動注入ボタンは
		// wp-admin/js/common.js の内部実装依存で、サードパーティが確実に
		// フックできるイベントを発火する保証がない。代わりに自前のボタンと
		// 自前のJSハンドラ(assets/js/admin-settings.js)で完結させる。
		$settings_url = admin_url( 'options-general.php?page=' . R2C_Settings_Page::SLUG );
		printf(
			'<div class="notice notice-info r2c-connect-notice"><p>%s <a href="%s">%s</a> <button type="button" class="notice-dismiss r2c-notice-dismiss" data-nonce="%s"><span class="screen-reader-text">%s</span></button></p></div>',
			esc_html__( 'The R2C plugin is not connected yet.', 'r2c-ai-concierge' ),
			esc_url( $settings_url ),
			esc_html__( 'Connect', 'r2c-ai-concierge' ),
			esc_attr( wp_create_nonce( self::DISMISS_ACTION ) ),
			esc_html__( 'Dismiss this notice', 'r2c-ai-concierge' )
		);
	}

	public static function handle_dismiss() {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		R2C_Options::dismiss_notice();
		wp_send_json_success();
	}
}
