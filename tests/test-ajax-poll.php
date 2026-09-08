<?php
/**
 * R2C_AI_Concierge_Ajax::handle_poll() — the most branch-heavy method in the plugin, and
 * the least covered before this file: only the "already provisioned with a
 * key" happy path is reachable through the E2E connect spec (via the mock's
 * poll_attempts_until_provisioned:0 shortcut). Every other outcome —
 * no-token-pending, a 404 from R2C, a transport-level outage, the
 * "provisioned but no key" safety net, expired/failed terminal states, and
 * every wait_reason/verify_reason message — was entirely untested.
 *
 * The single most important behavioural distinction here is between
 * "terminal" (clear local state, stop polling) and "pending" (keep local
 * state, keep polling) — getting this wrong in either direction either
 * strands the admin in an infinite poll loop or throws away a connection
 * attempt that was still in progress (NFR-06: a transient network hiccup
 * must not look like a failure).
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxPollTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.example/' );
		Functions\when( 'update_option' )->justReturn( true );

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
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs the transport as if R2C_AI_Concierge_Api_Client::poll() reached R2C and got a
	 * 2xx JSON body back.
	 */
	private function stub_poll_response( array $body, $status = 200 ) {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( $body ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP response body.
		);
	}

	public function test_no_pending_token_is_terminal_without_calling_r2c() {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_remote_request' )->never();

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertTrue( $e->data['terminal'] );
			$this->assertSame( 'No connection attempt is in progress. Please start over.', $e->data['message'] );
		}
	}

	public function test_404_from_r2c_clears_local_state_and_is_terminal() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response( array( 'status' => 'not_found' ), 404 );

		$cleared = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) use ( &$cleared ) {
				$cleared[] = $name;
				return true;
			}
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertTrue( $e->data['terminal'] );
			$this->assertSame( 'The connection attempt could not be found. Please start over.', $e->data['message'] );
		}

		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_POLL, $cleared );
		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_CHALLENGE, $cleared );
	}

	public function test_transport_failure_stays_pending_and_never_clears_state() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );

		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);
		Functions\expect( 'delete_transient' )->never();

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success, 'a transient outage must not be reported as an error — JS keeps polling only on success:true' );
			$this->assertSame( 'pending', $e->data['status'] );
			$this->assertSame( 'Unable to reach R2C right now. Please try again in a moment.', $e->data['message'] );
		}
	}

	public function test_provisioned_with_api_key_connects_and_clears_pending_state() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response(
			array(
				'status'    => 'provisioned',
				'api_key'   => 'key_123',
				'tenant_id' => 't_1',
			)
		);

		$options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		$cleared = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) use ( &$cleared ) {
				$cleared[] = $name;
				return true;
			}
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$this->assertSame( 'key_123', $options[ \R2C_AI_Concierge_Options::API_KEY ] );
		$this->assertSame( 't_1', $options[ \R2C_AI_Concierge_Options::TENANT_ID ] );
		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_POLL, $cleared );
		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_CHALLENGE, $cleared );
	}

	/**
	 * Documented safety-net branch in handle_poll()'s own comments: R2C
	 * finished issuing a key but this poll response didn't carry it (a
	 * dropped response, a retried poll landing after the key was already
	 * consumed, etc). The admin must be pointed at the manual-key fallback
	 * rather than left in an infinite "pending" loop or a silent failure.
	 */
	public function test_provisioned_without_api_key_clears_pending_state_and_guides_to_manual_key() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response(
			array(
				'status'    => 'provisioned',
				'tenant_id' => 't_1',
			)
		);
		Functions\expect( 'update_option' )->never();
		$cleared = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) use ( &$cleared ) {
				$cleared[] = $name;
				return true;
			}
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'issued_without_key', $e->data['status'] );
			$this->assertStringContainsString( 'Enter API key manually', $e->data['message'] );
		}

		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_POLL, $cleared );
		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_CHALLENGE, $cleared );
	}

	public function test_expired_status_clears_pending_state() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response( array( 'status' => 'expired' ) );
		Functions\expect( 'delete_transient' )->twice();

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'expired', $e->data['status'] );
			$this->assertSame( 'Site verification expired before it could complete. Please try again.', $e->data['message'] );
		}
	}

	public function test_failed_status_with_known_reason_shows_specific_message() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response(
			array(
				'status' => 'failed',
				'reason' => 'site_unreachable',
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'failed', $e->data['status'] );
			$this->assertSame( 'Site verification failed. Please try again.', $e->data['message'] );
		}
	}

	public function test_failed_status_with_unknown_reason_falls_back_to_generic_message() {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response(
			array(
				'status' => 'failed',
				'reason' => 'some_new_reason_this_test_has_never_heard_of',
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Connection failed. Please try again.', $e->data['message'] );
		}
	}

	/**
	 * Every wait_reason / verify_reason branch of pending_reason_message().
	 * Each one is a distinct, user-facing string that a WP-11-style i18n
	 * refactor could silently rename or misroute without any test noticing.
	 *
	 * @dataProvider pendingReasonProvider
	 */
	public function test_pending_reason_messages( array $body, $expectedMessage ) {
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		$this->stub_poll_response( array_merge( array( 'status' => 'pending' ), $body ) );
		Functions\when( 'delete_transient' )->justReturn( true );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'pending', $e->data['status'] );
			$this->assertSame( $expectedMessage, $e->data['message'] );
		}
	}

	public function pendingReasonProvider() {
		return array(
			'wait_reason: capacity_reached'        => array(
				array( 'wait_reason' => 'capacity_reached' ),
				'New sign-ups to R2C are currently at capacity. Please wait a moment.',
			),
			'wait_reason: daily_limit_reached'     => array(
				array( 'wait_reason' => 'daily_limit_reached' ),
				'Today\'s sign-up limit has been reached. Please try again tomorrow.',
			),
			'verify_reason: http_error'            => array(
				array( 'verify_reason' => 'http_error' ),
				'Verification access to this site was denied. Please temporarily disable Basic Auth or any access restrictions.',
			),
			'verify_reason: blocked'               => array(
				array( 'verify_reason' => 'blocked' ),
				'This site cannot be reached from outside. It must be a publicly accessible site (local environments cannot connect).',
			),
			'verify_reason: unreachable'           => array(
				array( 'verify_reason' => 'unreachable' ),
				'This site cannot be reached from outside. It must be a publicly accessible site (local environments cannot connect).',
			),
			'verify_reason: invalid_body'          => array(
				array( 'verify_reason' => 'invalid_body' ),
				'Site verification failed. Please make sure the plugin is activated.',
			),
			'verify_reason: challenge_mismatch'    => array(
				array( 'verify_reason' => 'challenge_mismatch' ),
				'Site verification failed. Please make sure the plugin is activated.',
			),
			'verify_reason: unrecognized code'     => array(
				array( 'verify_reason' => 'some_future_reason_code' ),
				'Verifying your site…',
			),
			'no reason at all yet'                 => array(
				array(),
				'Verifying your site…',
			),
		);
	}
}
