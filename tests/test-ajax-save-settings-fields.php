<?php
/**
 * R2C_Ajax::handle_save_settings() — the position/offset/color validation
 * and the "not connected" / "nothing to change" guards. Complements
 * test-ajax-save-settings.php, which only covers excluded_page_patterns.
 *
 * The HTML inputs have client-side hints (a `<select>` for position, `min`/
 * `max` on the offset number inputs, a color-picker class), but none of
 * those are enforced by the browser against a hand-crafted request — the
 * PHP validation here is the only real gate before a value reaches R2C.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ajax.php';

class AjaxSaveSettingsFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only stub for the real wp_json_encode.
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

	private function stub_connected( $connected = true ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $connected ) {
				if ( \R2C_Options::API_KEY === $name ) {
					return $connected ? 'connected-key' : '';
				}
				return $default;
			}
		);
	}

	private function stub_patch_success( array &$captured ) {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( array( 'tenant_id' => 't_1' ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP response body.
		);
	}

	public function test_disconnected_site_is_rejected_before_touching_the_api() {
		$this->stub_connected( false );
		Functions\expect( 'wp_remote_request' )->never();

		$_POST['position'] = 'bottom-left';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Not connected.', $e->data['message'] );
		}
	}

	public function test_unrecognized_position_value_is_dropped_not_forwarded() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		// Neither of the two <select> options the UI offers — a
		// hand-crafted request or a future stray value.
		$_POST['position']  = 'top-left';
		$_POST['offset_x']  = '10';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertArrayNotHasKey( 'position', $captured );
		$this->assertSame( 10, $captured['offset_x'] );
	}

	public function test_invalid_hex_color_is_dropped_not_forwarded() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		Functions\when( 'sanitize_hex_color' )->justReturn( '' ); // real WP returns null/'' for invalid input.

		$_POST['primary_color'] = 'not-a-color';
		$_POST['offset_y']      = '5';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertArrayNotHasKey( 'primary_color', $captured );
		$this->assertSame( 5, $captured['offset_y'] );
	}

	/**
	 * absint() strips the sign rather than rejecting the value — a
	 * hand-crafted negative offset silently becomes its positive magnitude
	 * rather than being dropped. Documented here so a future change to this
	 * behaviour is a deliberate decision, not an accident.
	 */
	public function test_negative_offset_is_coerced_to_positive_by_absint_not_rejected() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		$_POST['offset_x'] = '-50';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( 50, $captured['offset_x'] );
	}

	/**
	 * Neither the AJAX handler nor R2C_Api_Client clamps to the 0-320 range
	 * the settings screen's `min`/`max` attributes suggest — that range is
	 * a browser-side hint only. A value submitted outside it (e.g. via
	 * devtools, or a future JS bug) is forwarded to R2C completely as-is.
	 * This test pins that actual behaviour; see the accompanying risk note
	 * for why this may be worth a real server-side clamp.
	 */
	public function test_out_of_range_offset_is_forwarded_unclamped() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		$_POST['offset_x'] = '99999';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( 99999, $captured['offset_x'] );
	}

	public function test_non_numeric_offset_becomes_zero_and_is_still_sent() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		$_POST['offset_x'] = 'not-a-number';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertSame( 0, $captured['offset_x'] );
	}

	public function test_empty_string_offset_is_treated_as_absent_not_zero() {
		$this->stub_connected();
		$captured = array();
		$this->stub_patch_success( $captured );

		$_POST['offset_x']      = '';
		$_POST['primary_color'] = '#336699';
		Functions\when( 'sanitize_hex_color' )->returnArg( 1 );

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
		}

		$this->assertArrayNotHasKey( 'offset_x', $captured );
		$this->assertSame( '#336699', $captured['primary_color'] );
	}

	/**
	 * Every submitted field fails validation (bad position, bad color) and
	 * nothing else was sent — $fields ends up empty. This must short-circuit
	 * with "nothing to change" rather than firing an effectively-empty
	 * PATCH request at R2C.
	 */
	public function test_all_fields_invalid_reports_nothing_to_change_without_calling_the_api() {
		$this->stub_connected();
		Functions\expect( 'wp_remote_request' )->never();
		Functions\when( 'sanitize_hex_color' )->justReturn( '' );

		$_POST['position']      = 'diagonally';
		$_POST['primary_color'] = 'javascript:alert(1)';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 'There is nothing to change.', $e->data['message'] );
		}
	}

	/**
	 * A PATCH failure must not overwrite the read-through widget cache with
	 * whatever partial/garbage data might otherwise be in scope — the
	 * front-end must keep serving the last known-good theme rather than a
	 * blank one, per R2C_Options' own "cache is refreshed only on success"
	 * contract.
	 */
	public function test_patch_failure_surfaces_error_and_does_not_touch_the_cached_theme() {
		$this->stub_connected();

		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"mocked outage"}' );

		$updatedOptionNames = array();
		Functions\when( 'update_option' )->alias(
			function ( $name ) use ( &$updatedOptionNames ) {
				$updatedOptionNames[] = $name;
				return true;
			}
		);

		$_POST['offset_x'] = '10';

		try {
			\R2C_Ajax::handle_save_settings();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'mocked outage', $e->data['message'] );
		}

		$this->assertNotContains( \R2C_Options::CACHED_THEME, $updatedOptionNames );
	}
}
