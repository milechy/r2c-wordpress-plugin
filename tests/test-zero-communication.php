<?php
/**
 * WP-9 core invariant: while disconnected, this plugin never talks to
 * api.r2c.biz — not from the front-end widget hook, not from the admin
 * notice (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md GL#7 / NFR-05).
 *
 * R2C_Api_Client is the sole caller of wp_remote_request() (see its class
 * doc), so asserting wp_remote_request() is never invoked is equivalent to
 * asserting R2C_Api_Client is never invoked — without needing to reach into
 * its private implementation.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-widget.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-settings-page.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-notice.php';

class ZeroCommunicationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_widget_render_never_calls_remote_when_disconnected() {
		Functions\expect( 'wp_remote_request' )->never();

		Functions\when( 'is_admin' )->justReturn( false );
		// R2C_Options::get_api_key() -> get_option( API_KEY, '' ); empty string means not connected.
		Functions\when( 'get_option' )->justReturn( '' );

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Disconnected widget render must emit nothing.' );
	}

	public function test_notice_maybe_render_never_calls_remote_when_disconnected() {
		Functions\expect( 'wp_remote_request' )->never();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' ); // not connected, not dismissed
		Functions\when( 'get_current_screen' )->justReturn( false ); // not on the settings page
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );

		ob_start();
		\R2C_Notice::maybe_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'r2c-connect-notice', $output, 'Disconnected + not dismissed must still show the connect notice.' );
	}

	public function test_notice_maybe_enqueue_never_calls_remote_when_disconnected() {
		Functions\expect( 'wp_remote_request' )->never();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_current_screen' )->justReturn( false );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_localize_script' )->once();

		\R2C_Notice::maybe_enqueue();
	}

	public function test_notice_is_silent_once_dismissed() {
		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		Functions\when( 'current_user_can' )->justReturn( true );
		// NOTICE_DISMISSED option truthy -> is_notice_dismissed() true -> should_show() false.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return \R2C_Options::NOTICE_DISMISSED === $name ? true : $default;
			}
		);
		Functions\when( 'get_current_screen' )->justReturn( false );

		ob_start();
		\R2C_Notice::maybe_render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
