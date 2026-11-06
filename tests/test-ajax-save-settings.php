<?php
/**
 * FR-10: R2C_AI_Concierge_Ajax::handle_save_settings() must turn the excluded-pages
 * textarea (one pattern per line, possibly with helper-added duplicates or
 * blank lines) into the exact array R2C_AI_Concierge_Api_Client::patch_settings() sends —
 * trimmed, de-duplicated, blank lines dropped — and must treat an
 * intentionally emptied textarea as "clear all patterns", not "no change".
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxSaveSettingsExcludedPatternsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_hex_color' )->returnArg( 1 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only stub for the real wp_json_encode.
			}
		);

		// R2C_AI_Concierge_Options::is_connected() / get_api_key() -> get_option( API_KEY ).
		// The management code is saved, so the excluded-pages field is sent (without one it is not).
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( \R2C_AI_Concierge_Options::API_KEY === $name ) {
					return 'connected-key';
				}
				if ( \R2C_AI_Concierge_Options::MANAGE_TOKEN === $name ) {
					return 'r2cm_secret';
				}
				return $default;
			}
		);

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

	/**
	 * Stubs the wp_remote_request transport so R2C_AI_Concierge_Api_Client::patch_settings()
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
		$captured = array();
		$this->captureRemoteRequestBody( $captured );

		$_POST['excluded_page_patterns'] = "/cart\n  /checkout/*  \n\n/cart\n/blog/*\n";

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array( '/cart', '/checkout/*', '/blog/*' ), $captured['excluded_page_patterns'] );
	}

	public function test_empty_textarea_clears_all_patterns_rather_than_being_ignored() {
		$captured = array();
		$this->captureRemoteRequestBody( $captured );

		$_POST['excluded_page_patterns'] = '';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array(), $captured['excluded_page_patterns'] );
	}

	public function test_absent_excluded_page_patterns_key_is_not_sent_at_all() {
		$captured = array();
		$this->captureRemoteRequestBody( $captured );

		unset( $_POST['excluded_page_patterns'] );
		$_POST['primary_color'] = '#3B82F6';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertArrayNotHasKey( 'excluded_page_patterns', $captured );
	}

	/**
	 * A line containing only spaces/tabs is not the same as a truly blank
	 * line to a careless split-and-filter — trim() must run before the
	 * strlen() emptiness check, or whitespace-only lines would survive as
	 * bogus "patterns" R2C then has to reject or (worse) silently match
	 * everything with.
	 */
	public function test_whitespace_only_lines_are_dropped_like_blank_lines() {
		$captured = array();
		$this->captureRemoteRequestBody( $captured );

		$_POST['excluded_page_patterns'] = "/cart\n   \n\t\n/checkout/*";

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array( '/cart', '/checkout/*' ), $captured['excluded_page_patterns'] );
	}
	private function stub_connection_with_token( $token ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $token ) {
				if ( \R2C_AI_Concierge_Options::API_KEY === $name ) {
					return 'connected-key';
				}
				if ( \R2C_AI_Concierge_Options::MANAGE_TOKEN === $name ) {
					return $token;
				}
				return $default;
			}
		);
	}

	private function capture_request( array &$captured, $status = 200, $body = '{}' ) {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'headers' => $args['headers'],
					'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
				);
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	public function test_the_management_code_is_sent_as_a_header_and_never_in_the_body() {
		$captured = array();
		$this->capture_request( $captured );

		$_POST['excluded_page_patterns'] = '/cart';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( 'r2cm_secret', $captured['headers']['x-r2c-manage-token'] );
		$this->assertStringNotContainsString( 'r2cm_secret', (string) json_encode( $captured['body'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only.
	}

	/**
	 * 管理コードが無いと、R2C は非表示ページの変更を断り、同じ要求の位置・色も保存しない。
	 * だから送らない(位置・色の保存を巻き込まない)。
	 */
	public function test_without_a_management_code_the_excluded_pages_are_not_sent_and_the_rest_still_saves() {
		$this->stub_connection_with_token( '' );
		$captured = array();
		$this->capture_request( $captured );

		$_POST['excluded_page_patterns'] = '/cart';
		$_POST['position']               = 'bottom-left';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( array( 'position' => 'bottom-left' ), $captured['body'] );
		$this->assertArrayNotHasKey( 'x-r2c-manage-token', $captured['headers'] );
	}

	public function test_only_excluded_pages_without_a_management_code_is_nothing_to_change() {
		$this->stub_connection_with_token( '' );
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['excluded_page_patterns'] = '/cart';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 'There is nothing to change.', $e->data['message'] );
		}
	}

	public function test_a_rejected_management_code_shows_what_to_do_next() {
		$captured = array();
		$this->capture_request( $captured, 403, '{"error":"manage_token_required","message":"日本語"}' );

		$_POST['excluded_page_patterns'] = '/cart';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertStringContainsString( 'management code', $e->data['message'] );
			$this->assertStringNotContainsString( '日本語', $e->data['message'] );
		}
	}
}
