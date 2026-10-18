<?php
/**
 * R2C_AI_Concierge_Ajax::handle_connect() — FR-02's server-side consent backstop, email
 * validation, and the provision() failure/success branches.
 *
 * None of this is exercised anywhere else: the E2E "connect" spec only
 * drives the happy path through the browser, and the connect form's own
 * `required` attributes (email, consent) block an empty/unchecked submission
 * from ever reaching the server in that test. A hand-crafted request (or a
 * future JS bug that stops setting `consent`) would skip that client-side
 * gate entirely — this is exactly the case FR-02 calls out as needing a
 * server-side check independent of the JS.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxConnectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.example/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new \R2CTestJsonExit( true, $data );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				throw new \R2CTestJsonExit( false, $data );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_missing_consent_is_rejected_before_any_remote_call() {
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['email'] = 'owner@example.com';
		// consent intentionally absent.

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 'Please check the consent checkbox.', $e->data['message'] );
		}
	}

	public function test_consent_explicitly_zero_is_rejected_the_same_as_absent() {
		Functions\expect( 'wp_remote_request' )->never();

		// admin-settings.js always sends consent: checked ? '1' : '0' — an
		// unchecked box is the string '0', not an absent key. Both must be
		// treated as "no consent"; only the exact string '1' passes.
		$_POST['email']   = 'owner@example.com';
		$_POST['consent'] = '0';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 'Please check the consent checkbox.', $e->data['message'] );
		}
	}

	public function test_empty_email_is_rejected() {
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['consent'] = '1';
		$_POST['email']   = '';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a valid email address.', $e->data['message'] );
		}
	}

	public function test_malformed_email_is_rejected() {
		Functions\expect( 'wp_remote_request' )->never();
		Functions\when( 'is_email' )->justReturn( false );

		$_POST['consent'] = '1';
		$_POST['email']   = 'not-an-email';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a valid email address.', $e->data['message'] );
		}
	}

	/**
	 * sanitize_email() has no built-in guard against non-scalar input
	 * (unlike sanitize_text_field()) — passing it an array reaches
	 * strlen() internally, a fatal TypeError on PHP 8+. A hand-crafted
	 * `email[]=x` submission (or a buggy client duplicating the field
	 * name) must be rejected the same as a missing/invalid email, not
	 * crash the request.
	 */
	public function test_email_submitted_as_an_array_is_rejected_gracefully() {
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['consent'] = '1';
		$_POST['email']   = array( 'owner@example.com' );

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 'Please enter a valid email address.', $e->data['message'] );
		}
	}

	public function test_provision_transport_failure_shows_unreachable_message() {
		Functions\when( 'is_email' )->justReturn( true );

		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$_POST['consent'] = '1';
		$_POST['email']   = 'owner@example.com';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Unable to reach R2C right now. Please try again in a moment.', $e->data['message'] );
		}
	}

	/**
	 * The server's `message` is Japanese-only. An English-locale owner must
	 * see the plugin's own translatable string chosen by the `error` code,
	 * never the server text.
	 *
	 * @dataProvider provision_error_codes
	 */
	public function test_provision_error_shows_plugin_text_not_the_japanese_server_message( $status, $code, $expected ) {
		Functions\when( 'is_email' )->justReturn( true );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"' . $code . '","message":"サイトURLは https:// で始まる必要があります。"}' );

		$_POST['consent'] = '1';
		$_POST['email']   = 'owner@example.com';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( $expected, $e->data['message'] );
			$this->assertDoesNotMatchRegularExpression( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $e->data['message'] );
		}
	}

	public function provision_error_codes() {
		return array(
			'not https'           => array( 400, 'site_url_not_https', 'Your site address must use HTTPS.' ),
			'not public'          => array( 400, 'site_url_not_public', 'This site cannot be reached from outside. It must be a publicly accessible site (local environments cannot connect).' ),
			'rejected pattern'    => array( 400, 'site_url_rejected', 'This site address cannot be registered. Please check the address in Settings > General.' ),
			'invalid url'         => array( 400, 'invalid_site_url', 'This site address cannot be registered. Please check the address in Settings > General.' ),
			'service unavailable' => array( 503, 'service_unavailable', 'This feature is not available right now. Please try again in a moment.' ),
			'rate limited'        => array( 429, 'rate_limit_exceeded', 'Too many attempts. Please wait a while and try again.' ),
			'unknown code'        => array( 500, 'provision_failed', 'Failed to start the connection.' ),
		);
	}

	public function test_provision_4xx_without_message_falls_back_to_generic_text() {
		Functions\when( 'is_email' )->justReturn( true );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$_POST['consent'] = '1';
		$_POST['email']   = 'owner@example.com';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Failed to start the connection.', $e->data['message'] );
		}
	}

	public function test_successful_provision_stores_pending_state_and_reports_waiting() {
		Functions\when( 'is_email' )->justReturn( true );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP response body.
				array(
					'poll_token'                     => 'poll_abc',
					'provisioning_expires_in_hours'  => 24,
					'challenge'                      => 'chal_xyz',
					'challenge_expires_in_minutes'   => 15,
				)
			)
		);

		$captured = array();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$captured ) {
				$captured[ $name ] = array( $value, $ttl );
				return true;
			}
		);

		$_POST['consent'] = '1';
		$_POST['email']   = 'owner@example.com';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
			$this->assertTrue( $e->data['waiting'] );
		}

		$this->assertSame( array( 'poll_abc', 24 * HOUR_IN_SECONDS ), $captured[ \R2C_AI_Concierge_Options::PENDING_POLL ] );
		$this->assertSame( array( 'chal_xyz', 15 * MINUTE_IN_SECONDS ), $captured[ \R2C_AI_Concierge_Options::PENDING_CHALLENGE ] );
	}
}
