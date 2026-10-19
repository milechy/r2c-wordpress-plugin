<?php
/**
 * R2C_AI_Concierge_Ajax::handle_connect_manual() — the FR-05 "I already have an account"
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

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

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
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a connection key.', $e->data['message'] );
		}
	}

	public function test_missing_api_key_field_is_rejected_the_same_as_empty() {
		Functions\expect( 'wp_remote_request' )->never();
		// $_POST['api_key'] intentionally never set.

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a connection key.', $e->data['message'] );
		}
	}

	public function test_401_response_is_reported_as_an_invalid_key() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"invalid_api_key"}' );

		$_POST['api_key'] = 'mock_not_a_real_key';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'This connection key is invalid.', $e->data['message'] );
		}
	}

	public function test_transport_failure_shows_the_unreachable_message() {
		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$_POST['api_key'] = 'mock_some_key';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Unable to reach R2C right now. Please try again in a moment.', $e->data['message'] );
		}
	}

	/**
	 * A non-401, non-transport failure (R2C reachable, but returning e.g. a
	 * 500 with no message body) must fall back to the generic verification
	 * text — distinct from both the 401 "invalid key" and the transport
	 * "unreachable" messages above.
	 */
	public function test_non_401_http_failure_falls_back_to_generic_verification_message() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$_POST['api_key'] = 'mock_some_key';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Failed to verify the connection key.', $e->data['message'] );
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
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$this->assertSame( 'mock_good_key', $options[ \R2C_AI_Concierge_Options::API_KEY ] );
		$this->assertSame( 't_manual', $options[ \R2C_AI_Concierge_Options::TENANT_ID ] );
		// 接続キーで繋いだ印(次の手順の案内から招待メールの文を外すために使う)。
		$this->assertTrue( $options[ \R2C_AI_Concierge_Options::CONNECTED_WITH_KEY ] );
		$theme = $options[ \R2C_AI_Concierge_Options::CACHED_THEME ];
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
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$theme = $options[ \R2C_AI_Concierge_Options::CACHED_THEME ];
		$this->assertNull( $theme['position'] );
		$this->assertNull( $theme['offset_x'] );
		$this->assertNull( $theme['offset_y'] );
		$this->assertNull( $theme['primary_color'] );
	}

	/* ---- WP-UX-07: コード丸ごと貼り付けでも接続できる ---- */

	/**
	 * Runs handle_connect_manual() against a mocked R2C that accepts exactly
	 * $accepted_key, and returns the x-api-key header value the plugin
	 * actually sent plus the resulting JSON response — so these tests assert
	 * on what reached R2C, not just on what was typed.
	 */
	private function submit_manual_input( $typed, $accepted_key ) {
		$sent_keys = array();
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$sent_keys ) {
				$sent_keys[] = isset( $args['headers']['x-api-key'] ) ? $args['headers']['x-api-key'] : null;
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$sent_keys, $accepted_key ) {
				return end( $sent_keys ) === $accepted_key ? 200 : 401;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"tenant_id":"t_paste"}' );

		$_POST['api_key'] = $typed;

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected a JSON response to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			return array( $e, $sent_keys );
		}
	}

	public function test_whole_script_tag_pasted_connects_with_the_data_api_key_value() {
		$tag = '<script src="https://api.r2c.biz/widget/t_paste.js" data-api-key="r2c_live_abcdef123456" async></script>';

		list( $e, $sent ) = $this->submit_manual_input( $tag, 'r2c_live_abcdef123456' );

		$this->assertTrue( $e->success );
		$this->assertSame( 'connected', $e->data['status'] );
		$this->assertSame( array( 'r2c_live_abcdef123456' ), $sent );
	}

	public function test_single_quoted_data_api_key_is_also_extracted() {
		$tag = "<script src='https://api.r2c.biz/widget.js' data-api-key='r2c_live_single'></script>";

		list( $e, $sent ) = $this->submit_manual_input( $tag, 'r2c_live_single' );

		$this->assertTrue( $e->success );
		$this->assertSame( array( 'r2c_live_single' ), $sent );
	}

	public function test_bare_key_still_works_unchanged() {
		list( $e, $sent ) = $this->submit_manual_input( 'r2c_live_barekey', 'r2c_live_barekey' );

		$this->assertTrue( $e->success );
		$this->assertSame( array( 'r2c_live_barekey' ), $sent );
	}

	public function test_bare_key_with_surrounding_whitespace_is_trimmed() {
		list( $e, $sent ) = $this->submit_manual_input( "  \n r2c_live_spaced \t ", 'r2c_live_spaced' );

		$this->assertTrue( $e->success );
		$this->assertSame( array( 'r2c_live_spaced' ), $sent );
	}

	/**
	 * No data-api-key attribute: nothing is extracted, so this behaves
	 * exactly as before — the pasted tag is stripped by sanitize_text_field()
	 * (stood in for here with strip_tags(), which is what removes the tag in
	 * production), leaving nothing, and no remote call is made. A different
	 * attribute's value (data-tenant) must never be mistaken for the key.
	 */
	public function test_text_without_data_api_key_is_not_extracted_and_is_rejected_without_a_remote_call() {
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $text ) {
				return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- test-only stand-in for sanitize_text_field()'s tag removal.
			}
		);
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['api_key'] = '<script src="https://api.r2c.biz/widget.js" data-tenant="t_paste"></script>';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a connection key.', $e->data['message'] );
		}
	}

	/**
	 * An extracted value is not exempt from the usual checks: an empty
	 * data-api-key is the same "enter a key" error with no remote call.
	 */
	public function test_empty_data_api_key_attribute_is_rejected_before_any_remote_call() {
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['api_key'] = '<script src="https://api.r2c.biz/widget.js" data-api-key=""></script>';

		try {
			\R2C_AI_Concierge_Ajax::handle_connect_manual();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a connection key.', $e->data['message'] );
		}
	}
}
