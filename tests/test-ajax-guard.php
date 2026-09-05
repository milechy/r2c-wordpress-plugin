<?php
/**
 * R2C_Ajax::guard() is the entry point every AJAX handler shares: nonce
 * check, then a `manage_options` capability check. Every other test file
 * stubs current_user_can() to true so it can get on with testing its own
 * handler — nothing exercises the capability-denied branch itself. A
 * regression here (e.g. the check silently dropped during a refactor) would
 * let a lower-privileged logged-in user disconnect the site or read/change
 * widget settings via a hand-crafted admin-ajax.php POST.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ajax.php';

class AjaxGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function expectJsonErrorWithStatus() {
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status_code = null ) {
				throw new \R2CTestJsonExit( false, $data, $status_code );
			}
		);
	}

	/**
	 * handle_disconnect() is the simplest handler behind guard() — good for
	 * proving the capability check itself without any other branch noise.
	 */
	public function test_non_admin_is_rejected_with_403_before_any_local_or_remote_change() {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		$this->expectJsonErrorWithStatus();

		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'get_option' )->never();

		try {
			\R2C_Ajax::handle_disconnect();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			$this->assertSame( 'You do not have permission to perform this action.', $e->data['message'] );
		}
	}

	/**
	 * Every other handler must fail the exact same way — guard() is shared,
	 * but nothing stops a future edit from adding a bypass to one handler
	 * specifically. Spot-check the two that touch the most state.
	 */
	public function test_non_admin_is_rejected_on_save_settings_too() {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		$this->expectJsonErrorWithStatus();

		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'update_option' )->never();

		$_POST = array( 'position' => 'bottom-left' );

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 403, $e->status_code );
		}

		$_POST = array();
	}
}
