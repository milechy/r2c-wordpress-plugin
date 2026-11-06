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

	/**
	 * Builds a get_option stub: connected with an API key, optionally with a
	 * management code, optionally flagged as "connected by pasting a key".
	 */
	private function stub_connection( $manage_token = '', $with_key = false ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $manage_token, $with_key ) {
				if ( \R2C_AI_Concierge_Options::API_KEY === $name ) {
					return 'connected-key';
				}
				if ( \R2C_AI_Concierge_Options::MANAGE_TOKEN === $name ) {
					return $manage_token;
				}
				if ( \R2C_AI_Concierge_Options::CONNECTED_WITH_KEY === $name ) {
					return $with_key;
				}
				return $default;
			}
		);
	}

	private function capture_deleted_options() {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		return function () use ( &$deleted ) {
			return $deleted;
		};
	}

	private function assert_local_state_cleared( array $deleted ) {
		$this->assertContains( \R2C_AI_Concierge_Options::API_KEY, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::TENANT_ID, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::SITE_ORIGIN, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::CACHED_THEME, $deleted );
		$this->assertContains( \R2C_AI_Concierge_Options::MANAGE_TOKEN, $deleted );
	}

	private function run_disconnect() {
		try {
			\R2C_AI_Concierge_Ajax::handle_disconnect();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			return $e->data;
		}
		return array();
	}

	public function test_remote_disconnect_success_sends_the_management_code_and_clears_local_state_with_no_warning() {
		$this->stub_connection( 'r2cm_secret' );
		$sent = null;
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$sent ) {
				$sent = array( $url, $args );
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"disconnected"}' );
		$deleted = $this->capture_deleted_options();

		$data = $this->run_disconnect();

		$this->assertSame( 'disconnected', $data['status'] );
		$this->assertNull( $data['warning'] );
		$this->assertStringEndsWith( '/v1/public/wp/disconnect', $sent[0] );
		$this->assertSame( 'connected-key', $sent[1]['headers']['x-api-key'] );
		$this->assertSame( 'r2cm_secret', $sent[1]['headers']['x-r2c-manage-token'] );
		$this->assert_local_state_cleared( $deleted() );
	}

	/**
	 * The core FR-07 guarantee: even when R2C itself is unreachable, the
	 * admin must still be able to stop the widget locally right now. Local
	 * state is cleared regardless, but a warning explains the key may still
	 * be technically live on R2C's side.
	 */
	public function test_remote_disconnect_failure_still_clears_local_state_with_a_warning() {
		$this->stub_connection( 'r2cm_secret' );
		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);
		$deleted = $this->capture_deleted_options();

		$data = $this->run_disconnect();

		$this->assertSame( 'disconnected', $data['status'] );
		$this->assertNotNull( $data['warning'] );
		// The local removal must not be skipped just because the remote
		// call failed — this is the entire point of FR-07.
		$this->assert_local_state_cleared( $deleted() );
	}

	public function test_a_rejected_management_code_still_clears_local_state_with_a_warning() {
		$this->stub_connection( 'r2cm_old_code' );
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"manage_token_required"}' );
		$deleted = $this->capture_deleted_options();

		$data = $this->run_disconnect();

		$this->assertNotNull( $data['warning'] );
		$this->assert_local_state_cleared( $deleted() );
	}

	/**
	 * A site connected before the management code existed (no code saved) must still be
	 * disconnectable. R2C would refuse the revoke, so no call is made; the warning says the key
	 * was not revoked and where to do it.
	 */
	public function test_without_a_management_code_the_remote_call_is_skipped_and_the_warning_says_so() {
		$this->stub_connection( '' );
		Functions\expect( 'wp_remote_request' )->never();
		$deleted = $this->capture_deleted_options();

		$data = $this->run_disconnect();

		$this->assertSame( 'disconnected', $data['status'] );
		$this->assertStringContainsString( 'management code', $data['warning'] );
		$this->assert_local_state_cleared( $deleted() );
	}

	/**
	 * ★契約のキーを失効させない★ 接続キーを貼って繋いだサイトの「接続を解除」は、
	 * R2C の管理画面で発行した契約のキーを、ほかの場所で使っていても止めてしまう。
	 * このサイトの接続情報を消すだけにして、R2C へは何も送らない。
	 */
	public function test_a_site_connected_with_a_pasted_key_never_revokes_the_key_on_r2c() {
		$this->stub_connection( 'r2cm_secret', true );
		Functions\expect( 'wp_remote_request' )->never();
		$deleted = $this->capture_deleted_options();

		$data = $this->run_disconnect();

		$this->assertSame( 'disconnected', $data['status'] );
		$this->assertNull( $data['warning'] );
		$this->assert_local_state_cleared( $deleted() );
	}
}
