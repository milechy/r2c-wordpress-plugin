<?php
/**
 * R2C_AI_Concierge_Ajax::handle_save_manage_token() — the field a site connected before the
 * management code existed (or connected without it) uses to add one. Nothing here talks to R2C:
 * the only way to check a management code against R2C is a call that changes settings, so the
 * format is checked here and R2C answers when the code is first used.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxSaveManageTokenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST = array();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return \R2C_AI_Concierge_Options::API_KEY === $name ? 'connected-key' : $default;
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
		Functions\expect( 'wp_remote_request' )->never();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_valid_code_is_stored_without_autoload() {
		$calls = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$calls ) {
				$calls[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		$_POST['manage_token'] = ' r2cm_abc123 ';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_manage_token();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'saved', $e->data['status'] );
		}

		$this->assertSame( array( 'r2cm_abc123', false ), $calls[ \R2C_AI_Concierge_Options::MANAGE_TOKEN ] );
	}

	public function test_an_empty_value_is_rejected_and_nothing_is_stored() {
		Functions\expect( 'update_option' )->never();
		$_POST['manage_token'] = '   ';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_manage_token();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Please enter a management code.', $e->data['message'] );
		}
	}

	public function test_the_connection_key_pasted_by_mistake_is_rejected_and_nothing_is_stored() {
		Functions\expect( 'update_option' )->never();
		$_POST['manage_token'] = 'rjc_the_connection_key';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_manage_token();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertStringContainsString( 'r2cm_', $e->data['message'] );
		}
	}

	public function test_not_connected_is_rejected() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'update_option' )->never();
		$_POST['manage_token'] = 'r2cm_abc';

		try {
			\R2C_AI_Concierge_Ajax::handle_save_manage_token();
			$this->fail( 'expected wp_send_json_error to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'Not connected.', $e->data['message'] );
		}
	}
}
