<?php
/**
 * FR-10: R2C_Ajax::handle_save_settings() must turn the excluded-pages
 * textarea (one pattern per line, possibly with helper-added duplicates or
 * blank lines) into the exact array R2C_Api_Client::patch_settings() sends —
 * trimmed, de-duplicated, blank lines dropped — and must treat an
 * intentionally emptied textarea as "clear all patterns", not "no change".
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ajax.php';

class AjaxSaveSettingsExcludedPatternsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_hex_color' )->returnArg( 1 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only stub for the real wp_json_encode.
			}
		);

		// R2C_Options::is_connected() / get_api_key() -> get_option( API_KEY ).
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return \R2C_Options::API_KEY === $name ? 'connected-key' : $default;
			}
		);

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new R2CTestJsonExit( true, $data );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				throw new R2CTestJsonExit( false, $data );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_POST );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs the wp_remote_request transport so R2C_Api_Client::patch_settings()
	 * "succeeds", and captures the JSON-decoded request body it was sent with.
	 */
	private function captureRemoteRequestBody( array &$capturedBody ) {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$capturedBody ) {
				$capturedBody = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP response body.
				array(
					'tenant_id' => 't_1',
					'position'  => 'bottom-right',
					'offset_x'  => 24,
					'offset_y'  => 24,
				)
			)
		);
	}

	public function test_textarea_lines_are_trimmed_deduplicated_and_blanks_dropped() {
		$captured = null;
		$this->captureRemoteRequestBody( $captured );

		$_POST['excluded_page_patterns'] = "/cart\n  /checkout/*  \n\n/cart\n/blog/*\n";

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array( '/cart', '/checkout/*', '/blog/*' ), $captured['excluded_page_patterns'] );
	}

	public function test_empty_textarea_clears_all_patterns_rather_than_being_ignored() {
		$captured = null;
		$this->captureRemoteRequestBody( $captured );

		$_POST['excluded_page_patterns'] = '';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array(), $captured['excluded_page_patterns'] );
	}

	public function test_absent_excluded_page_patterns_key_is_not_sent_at_all() {
		$captured = null;
		$this->captureRemoteRequestBody( $captured );

		unset( $_POST['excluded_page_patterns'] );
		$_POST['primary_color'] = '#3B82F6';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertArrayNotHasKey( 'excluded_page_patterns', $captured );
	}
}
