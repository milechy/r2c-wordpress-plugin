<?php
/**
 * R2C_Ajax::handle_connect_manual() — the FR-05 "I already have an account"
 * fallback. The E2E manual-connect spec only covers a non-empty valid key
 * and a non-empty invalid (401) key; the empty-key guard, non-401 failures,
 * and a response body missing the optional theme fields were untested.
 *
 * Note the HTML for #r2c-manual-key has no `required` attribute (unlike the
 * connect form's email/consent fields) — a user can submit this form with a
 * blank key with no browser-side gate at all, so the server-side empty
 * check below is the only thing standing between that click and a wasted
 * remote call.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ajax.php';

class AjaxConnectManualTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
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
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_api_key_is_rejected_before_any_remote_call() {
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['api_key'] = '';

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter an API key.', $e->data['message'] );
		}
	}

	public function test_missing_api_key_field_is_rejected_the_same_as_empty() {
		Functions\expect( 'wp_remote_request' )->never();
		// $_POST['api_key'] intentionally never set.

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter an API key.', $e->data['message'] );
		}
	}

	public function test_401_response_is_reported_as_an_invalid_key() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"invalid_api_key"}' );

		$_POST['api_key'] = 'mock_not_a_real_key';

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'This API key is invalid.', $e->data['message'] );
		}
	}

	public function test_non_401_failure_falls_back_to_generic_verification_message() {
		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$_POST['api_key'] = 'mock_some_key';

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Failed to verify the API key.', $e->data['message'] );
		}
	}

	public function test_successful_key_stores_connection_and_full_cached_theme() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP response body.
				array(
					'tenant_id'     => 't_manual',
					'position'      => 'bottom-left',
					'offset_x'      => 88,
					'offset_y'      => 12,
					'primary_color' => '#abcdef',
				)
			)
		);

		$options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);

		$_POST['api_key'] = 'mock_good_key';

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$this->assertSame( 'mock_good_key', $options[ \R2C_Options::API_KEY ] );
		$this->assertSame( 't_manual', $options[ \R2C_Options::TENANT_ID ] );
		$theme = $options[ \R2C_Options::CACHED_THEME ];
		$this->assertSame( 'bottom-left', $theme['position'] );
		$this->assertSame( 88, $theme['offset_x'] );
		$this->assertSame( '#abcdef', $theme['primary_color'] );
	}

	/**
	 * The API response only strictly promises tenant_id — position/offset/
	 * color are all isset()-guarded in handle_connect_manual(). A tenant
	 * with no widget customization saved yet must not fatal here.
	 */
	public function test_successful_key_with_no_theme_fields_stores_nulls_without_error() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"tenant_id":"t_bare"}' );

		$options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);

		$_POST['api_key'] = 'mock_bare_key';

		try {
			\R2C_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$theme = $options[ \R2C_Options::CACHED_THEME ];
		$this->assertNull( $theme['position'] );
		$this->assertNull( $theme['offset_x'] );
		$this->assertNull( $theme['offset_y'] );
		$this->assertNull( $theme['primary_color'] );
	}
}
