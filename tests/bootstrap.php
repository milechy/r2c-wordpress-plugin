<?php
/**
 * PHPUnit bootstrap. There is no WordPress install here — Brain Monkey
 * stubs WP functions per-test; this file only provides the handful of
 * constants/classes WP core would otherwise define, so that requiring a
 * class-r2c-*.php file (each guarded by `defined( 'ABSPATH' ) || exit;`)
 * doesn't kill the whole test run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'R2C_AI_CONCIERGE_VERSION' ) ) {
	define( 'R2C_AI_CONCIERGE_VERSION', '0.1.0-test' );
}
if ( ! defined( 'R2C_AI_CONCIERGE_API_BASE' ) ) {
	define( 'R2C_AI_CONCIERGE_API_BASE', 'https://api.r2c.biz' );
}
if ( ! defined( 'R2C_AI_CONCIERGE_URL' ) ) {
	define( 'R2C_AI_CONCIERGE_URL', 'https://example.com/wp-content/plugins/r2c-ai-concierge/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WP_Error / WP_REST_Response are core WP classes with no Composer package;
// Brain Monkey only stubs functions, not classes. These are the minimal
// stand-ins the classes under test actually touch (constructor + the one
// method/property each test reads) — not a general WP core reimplementation.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

/**
 * In real WordPress, wp_send_json_success()/wp_send_json_error() always end
 * the request via wp_die() — code after them is unreachable. Tests that stub
 * those two functions directly (rather than wp_die itself) must reproduce
 * that halting behaviour, or execution falls through into code that assumes
 * it already exited. Stub both to throw this, and assert on the caught
 * instance's ->success / ->data.
 */
class R2CTestJsonExit extends \Exception {
	public $success;
	public $data;

	public function __construct( $success, $data ) {
		parent::__construct( 'wp_send_json_' . ( $success ? 'success' : 'error' ) );
		$this->success = $success;
		$this->data    = $data;
	}
}
