<?php
/**
 * The unauthenticated /wp-json/r2c/v1/verify route (R2C_AI_Concierge_Verify_Endpoint)
 * must only ever reveal a challenge while one is actually pending — it is
 * the site-ownership proof R2C's server reads back during provisioning
 * (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md §5.2).
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-verify-endpoint.php';

class VerifyEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_404_when_no_challenge_is_pending() {
		Functions\when( 'get_transient' )->justReturn( false );

		$response = \R2C_AI_Concierge_Verify_Endpoint::handle();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->status );
		$this->assertSame( 'no_pending_challenge', $response->data['error'] );
	}

	public function test_returns_the_pending_challenge_when_one_exists() {
		Functions\when( 'get_transient' )->justReturn( 'abc123challenge' );

		$response = \R2C_AI_Concierge_Verify_Endpoint::handle();

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'abc123challenge', $response->data['challenge'] );
	}
}
