<?php
/**
 * WP-9: the plugin must stay harmless when R2C is unreachable or returns
 * garbage — no fatals, no exceptions, a uniform {ok:false,...} shape callers
 * (R2C_Ajax, R2C_Settings_Page) already branch on
 * (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md NFR-06).
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';

class ApiClientResilienceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_transport_failure_returns_graceful_failure_shape() {
		$error = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$result = \R2C_Api_Client::get_settings( 'fake-key' );

		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['status'] );
		$this->assertNull( $result['body'] );
		$this->assertSame( 'Connection timed out', $result['error'] );
	}

	public function test_non_json_body_is_not_mistaken_for_a_valid_empty_response() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 502 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '<html>Bad Gateway</html>' );

		$result = \R2C_Api_Client::get_settings( 'fake-key' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 502, $result['status'] );
		$this->assertNull( $result['body'] );
		$this->assertSame( 'invalid_json_response', $result['error'] );
	}

	public function test_valid_2xx_json_response_is_parsed_as_ok() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"tenant_id":"t_123","position":"bottom-right"}' );

		$result = \R2C_Api_Client::get_settings( 'fake-key' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 't_123', $result['body']['tenant_id'] );
		$this->assertNull( $result['error'] );
	}

	public function test_4xx_json_response_is_not_ok_but_body_is_still_parsed() {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"invalid api key"}' );

		$result = \R2C_Api_Client::get_settings( 'bad-key' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'invalid api key', $result['body']['message'] );
	}
}
