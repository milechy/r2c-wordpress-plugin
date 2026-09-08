<?php
/**
 * R2C_AI_Concierge_Ajax::handle_disconnect() — FR-07's guarantee that local credential
 * removal is unconditional, independent of whether R2C's own revoke call
 * succeeds. Neither branch of that independence was tested before this
 * file: only the E2E "disconnect" spec exists, and it only exercises the
 * both-succeed path (never a remote failure, and never a double-disconnect
 * with no local key to begin with).
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxDisconnectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'delete_option' )->justReturn( true );

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new \R2CTestJsonExit( true, $data );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A double-disconnect (double-click before the page reload lands, or a
	 * forged request with no prior connection) must not attempt a remote
	 * call with an empty api_key — and must still report success, since
	 * there is genuinely nothing left connected.
	 */
	public function test_disconnect_with_no_local_key_skips_the_remote_call_and_still_succeeds() {
		Functions\when( 'get_option' )->justReturn( '' ); // R2C_AI_Concierge_Options::get_api_key() -> ''
		Functions\expect( 'wp_remote_request' )->never();

		try {
			\R2C_AI_Concierge_Ajax::handle_disconnect();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'disconnected', $e->data['status'] );
			$this->assertNull( $e->data['warning'] );
		}
	}

	public function test_remote_disconnect_success_clears_local_state_with_no_warning() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return \R2C_AI_Concierge_Options::API_KEY === $name ? 'connected-key' : $default;
			}
		);
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"disconnected"}' );

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_disconnect();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'disconnected', $e->data['status'] );
			$this->assertNull( $e->data['warning'] );
		}

		$this->assertContains( \R2C_AI_Concierge_Options::API_KEY, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::TENANT_ID, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::SITE_ORIGIN, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::CACHED_THEME, $deleted );
	}

	/**
	 * The core FR-07 guarantee: even when R2C itself is unreachable, the
	 * admin must still be able to stop the widget locally right now. Local
	 * state is cleared regardless, but a warning explains the key may still
	 * be technically live on R2C's side.
	 */
	public function test_remote_disconnect_failure_still_clears_local_state_with_a_warning() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return \R2C_AI_Concierge_Options::API_KEY === $name ? 'connected-key' : $default;
			}
		);
		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_disconnect();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'disconnected', $e->data['status'] );
			$this->assertNotNull( $e->data['warning'] );
		}

		// The local removal must not be skipped just because the remote
		// call failed — this is the entire point of FR-07.
		$this->assertContains( \R2C_AI_Concierge_Options::API_KEY, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::TENANT_ID, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::SITE_ORIGIN, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::CACHED_THEME, $deleted );
	}
}
