<?php
/**
 * R2C_Notice::should_show()'s other two gates, and handle_dismiss() itself.
 * test-zero-communication.php already covers the disconnected+not-dismissed
 * case; this file covers the remaining should_show() branches (connected;
 * already on the settings page) and the dismiss AJAX handler, none of which
 * were tested anywhere.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-settings-page.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-notice.php';

class NoticeVisibilityAndDismissTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_notice_is_silent_once_connected_even_if_never_dismissed() {
		Functions\when( 'current_user_can' )->justReturn( true );
		// R2C_Options::is_connected() -> get_option( API_KEY ) truthy.
		Functions\when( 'get_option' )->justReturn( 'connected-key' );
		Functions\expect( 'get_current_screen' )->never();

		ob_start();
		\R2C_Notice::maybe_render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_notice_is_silent_while_already_on_the_r2c_settings_screen() {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' ); // not connected, not dismissed.

		$screen     = new \stdClass();
		$screen->id = 'settings_page_' . \R2C_Settings_Page::SLUG;
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		ob_start();
		\R2C_Notice::maybe_render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_notice_still_shows_on_a_different_settings_screen() {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );

		$screen     = new \stdClass();
		$screen->id = 'settings_page_some-other-plugin';
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		ob_start();
		\R2C_Notice::maybe_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'r2c-connect-notice', $output );
	}

	public function test_handle_dismiss_rejects_non_admin_with_403() {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status_code = null ) {
				throw new \R2CTestJsonExit( false, $data, $status_code );
			}
		);

		try {
			\R2C_Notice::handle_dismiss();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
		}
	}

	public function test_handle_dismiss_persists_dismissal_for_an_admin() {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$stored = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$stored ) {
				if ( \R2C_Options::NOTICE_DISMISSED === $name ) {
					$stored = $value;
				}
				return true;
			}
		);

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new \R2CTestJsonExit( true, $data );
			}
		);

		try {
			\R2C_Notice::handle_dismiss();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertTrue( $stored );
	}
}
