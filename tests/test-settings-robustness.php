<?php
/**
 * WP-UX-18: robustness of the settings screen — (a) the cached placement is
 * refreshed from R2C, (c) the connect poll does not hang or mislead, (d) a
 * changed/copied site address is detected, (e) the plan comes from the status
 * endpoint only and "could not be retrieved" is never turned into a plan or a
 * zero. (b) lives in the JS and is covered by the E2E spec.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-settings-page.php';

class SettingsRobustnessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->justReturn( false );
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
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Routes wp_remote_request() by the URL's path so one test can answer the
	 * poll, settings and status calls differently.
	 *
	 * @param array<string,array> $routes path-suffix => array( status, body ).
	 */
	private function stub_routes( array $routes ) {
		$current = array( 200, '{}' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url ) use ( $routes, &$current ) {
				$current = array( 404, '{}' );
				foreach ( $routes as $suffix => $response ) {
					if ( substr( $url, -strlen( $suffix ) ) === $suffix ) {
						$current = array( $response[0], json_encode( $response[1] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only fake HTTP body.
					}
				}
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$current ) {
				return $current[0];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$current ) {
				return $current[1];
			}
		);
	}

	private function call_private_static( $method, array $args = array() ) {
		$ref = new ReflectionMethod( '\R2C_AI_Concierge_Settings_Page', $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	/* ---- (a) the placement cache ---- */

	public function test_cache_theme_from_settings_stores_the_four_theme_fields() {
		$stored = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$stored ) {
				$stored[ $name ] = $value;
				return true;
			}
		);

		\R2C_AI_Concierge_Options::cache_theme_from_settings(
			array(
				'position'               => 'bottom-left',
				'offset_x'               => 40,
				'offset_y'               => 24,
				'primary_color'          => '#112233',
				'excluded_page_patterns' => array( '/cart' ),
			)
		);

		$this->assertSame(
			array(
				'position'      => 'bottom-left',
				'offset_x'      => 40,
				'offset_y'      => 24,
				'primary_color' => '#112233',
			),
			$stored[ \R2C_AI_Concierge_Options::CACHED_THEME ]
		);
	}

	public function test_cache_theme_from_settings_ignores_a_non_array_body() {
		Functions\expect( 'update_option' )->never();
		\R2C_AI_Concierge_Options::cache_theme_from_settings( null );
		$this->assertTrue( true ); // The expectation above is the assertion.
	}

	/**
	 * 自動接続の完了時にも、R2C に保存済みの位置を取り込む。取り込まないと、
	 * 既存テナントの位置が「設定画面を開くまで」サイトに出ない。
	 */
	public function test_automatic_connect_fetches_and_caches_the_theme_stored_on_r2c() {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.example/' );
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		Functions\when( 'delete_transient' )->justReturn( true );
		$stored = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$stored ) {
				$stored[ $name ] = $value;
				return true;
			}
		);
		$this->stub_routes(
			array(
				'/tok_abc'                => array(
					200,
					array(
						'status'    => 'provisioned',
						'api_key'   => 'key_123',
						'tenant_id' => 't_1',
					),
				),
				'/v1/public/wp/settings' => array(
					200,
					array(
						'position'      => 'bottom-left',
						'offset_x'      => 50,
						'offset_y'      => 24,
						'primary_color' => '#abcdef',
					),
				),
			)
		);

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'connected', $e->data['status'] );
		}

		$this->assertSame( 'bottom-left', $stored[ \R2C_AI_Concierge_Options::CACHED_THEME ]['position'] );
		$this->assertSame( '#abcdef', $stored[ \R2C_AI_Concierge_Options::CACHED_THEME ]['primary_color'] );
	}

	/* ---- (c) the connect poll ---- */

	/**
	 * WP 側の challenge は15分で消えるが、R2C は最大24時間 pending を返し続ける。
	 * 放置すると「Basic認証を解除して」と誤案内したまま終わらない。
	 */
	public function test_pending_with_a_vanished_challenge_ends_the_wait_as_failed() {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return \R2C_AI_Concierge_Options::PENDING_POLL === $name ? 'tok_abc' : false;
			}
		);
		$cleared = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) use ( &$cleared ) {
				$cleared[] = $name;
				return true;
			}
		);
		$this->stub_routes( array( '/tok_abc' => array( 200, array( 'status' => 'pending', 'verify_reason' => 'http_error' ) ) ) );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'failed', $e->data['status'], 'failed is a terminal status in admin-settings.js, so polling stops' );
			$this->assertStringContainsString( 'Please press "Connect" again', $e->data['message'] );
			$this->assertStringNotContainsString( 'Basic Auth', $e->data['message'] );
		}
		$this->assertContains( \R2C_AI_Concierge_Options::PENDING_POLL, $cleared );
	}

	public function test_pending_with_a_live_challenge_keeps_waiting() {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( 'something' );
		Functions\expect( 'delete_transient' )->never();
		$this->stub_routes( array( '/something' => array( 200, array( 'status' => 'pending' ) ) ) );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertSame( 'pending', $e->data['status'] );
		}
	}

	/**
	 * @dataProvider serverErrorStatusProvider
	 */
	public function test_a_5xx_from_r2c_is_a_server_problem_not_unreachable_and_keeps_polling( $http_status ) {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( 'tok_abc' );
		Functions\expect( 'delete_transient' )->never();
		$this->stub_routes( array( '/tok_abc' => array( $http_status, array( 'error' => 'internal_error' ) ) ) );

		try {
			\R2C_AI_Concierge_Ajax::handle_poll();
			$this->fail( 'expected wp_send_json_success to halt execution' );
		} catch ( \R2CTestJsonExit $e ) {
			$this->assertTrue( $e->success );
			$this->assertSame( 'pending', $e->data['status'] );
			$this->assertStringContainsString( 'R2C is having a temporary problem', $e->data['message'] );
			$this->assertStringNotContainsString( 'Unable to reach', $e->data['message'] );
		}
	}

	public function serverErrorStatusProvider() {
		return array(
			'500' => array( 500 ),
			'502' => array( 502 ),
			'503' => array( 503 ),
		);
	}

	/* ---- (d) address mismatch ---- */

	/**
	 * @dataProvider addressProvider
	 */
	public function test_origin_mismatch( $saved, $current, $expected_mismatch ) {
		Functions\when( 'get_option' )->justReturn( $saved );
		Functions\when( 'home_url' )->justReturn( $current );

		$mismatch = $this->call_private_static( 'origin_mismatch' );

		if ( $expected_mismatch ) {
			$this->assertNotNull( $mismatch );
		} else {
			$this->assertNull( $mismatch );
		}
	}

	public function addressProvider() {
		return array(
			'same address'                         => array( 'https://shop.example/', 'https://shop.example/', false ),
			'path and host case are ignored'       => array( 'https://Shop.Example/blog/', 'https://shop.example/', false ),
			'default port is ignored'              => array( 'https://shop.example:443/', 'https://shop.example/', false ),
			'different domain (domain change)'     => array( 'https://shop.example/', 'https://new-shop.example/', true ),
			'http to https'                        => array( 'http://shop.example/', 'https://shop.example/', true ),
			'staging copy on a subdomain'          => array( 'https://shop.example/', 'https://staging.shop.example/', true ),
			'other port'                           => array( 'https://shop.example/', 'https://shop.example:8443/', true ),
			'no saved address (older connection)'  => array( '', 'https://shop.example/', false ),
		);
	}

	public function test_mismatch_notice_names_both_addresses_and_warns_about_disconnecting() {
		Functions\when( 'get_option' )->justReturn( 'https://old.example/' );
		Functions\when( 'home_url' )->justReturn( 'https://new.example/' );

		ob_start();
		$this->call_private_static( 'render_origin_mismatch_notice' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'https://old.example', $html );
		$this->assertStringContainsString( 'https://new.example', $html );
		$this->assertStringContainsString( 'also stop the original site', $html );
		$this->assertStringContainsString( 'Add https://new.example to the allowed domains', $html );
	}

	public function test_no_notice_and_no_disconnect_caution_when_the_address_is_unchanged() {
		Functions\when( 'get_option' )->justReturn( 'https://same.example/' );
		Functions\when( 'home_url' )->justReturn( 'https://same.example/' );

		ob_start();
		$this->call_private_static( 'render_origin_mismatch_notice' );
		$this->call_private_static( 'render_disconnect_button' );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'r2c-origin-mismatch', $html );
		$this->assertStringContainsString( 'id="r2c-disconnect-button"', $html );
	}

	public function test_disconnect_button_carries_the_copy_warning_on_mismatch() {
		Functions\when( 'get_option' )->justReturn( 'https://old.example/' );
		Functions\when( 'home_url' )->justReturn( 'https://new.example/' );

		ob_start();
		$this->call_private_static( 'render_disconnect_button' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'stops the chat on the original site', $html );
	}

	/* ---- (e) plan display ---- */

	private function status_result( $plan ) {
		return array(
			'ok'     => true,
			'status' => 200,
			'body'   => array( 'plan' => $plan ),
			'error'  => null,
		);
	}

	private function summary_html( $settings_body, $status_result ) {
		Functions\when( 'get_option' )->justReturn( '' );
		ob_start();
		$this->call_private_static( 'render_status_summary', array( $settings_body, $status_result ) );
		return ob_get_clean();
	}

	public function test_summary_plan_comes_from_the_status_endpoint_not_the_settings_response() {
		$html = $this->summary_html(
			array(
				'tenant_id' => 't_1',
				'is_active' => true,
				'plan'      => 'free_ad',
			),
			$this->status_result( 'growth' )
		);

		$this->assertStringContainsString( 'Growth plan', $html );
		$this->assertStringNotContainsString( 'Free plan', $html );
	}

	/**
	 * @dataProvider unavailablePlanProvider
	 */
	public function test_summary_never_invents_a_plan_when_status_could_not_determine_it( $status_result ) {
		$html = $this->summary_html(
			array(
				'tenant_id' => 't_1',
				'is_active' => true,
				'plan'      => 'free_ad', // the settings response must not leak back in as the plan.
			),
			$status_result
		);

		$this->assertStringContainsString( 'Could not be retrieved', $html );
		$this->assertStringNotContainsString( 'Free plan', $html );
	}

	public function unavailablePlanProvider() {
		return array(
			'plan is null'               => array( array( 'ok' => true, 'status' => 200, 'body' => array( 'plan' => null ), 'error' => null ) ),
			'plan is missing'            => array( array( 'ok' => true, 'status' => 200, 'body' => array(), 'error' => null ) ),
			'status endpoint failed'     => array( array( 'ok' => false, 'status' => 500, 'body' => null, 'error' => null ) ),
			'status was not fetched'     => array( null ),
		);
	}

	public function test_usage_block_shows_null_values_as_not_retrieved_but_keeps_real_zeros() {
		Functions\when( 'get_option' )->justReturn( '' );
		$result = array(
			'ok'     => true,
			'status' => 200,
			'body'   => array(
				'plan'                   => null,
				'avatar_active'          => null,
				'faq_published_count'    => 0,
				'open_escalations_count' => null,
				'weekly_summary'         => array(
					'sessions_this_week' => null,
					'learned_this_week'  => 3,
				),
			),
			'error'  => null,
		);

		ob_start();
		$this->call_private_static( 'render_usage_status', array( $result ) );
		$html = ob_get_clean();

		// 取得できなかった4つ(会話数・アバター・未対応件数、学習件数は片方が null で行ごと)。
		$this->assertSame( 3, substr_count( $html, 'Could not be retrieved' ) );
		// 本当の 0 は 0 のまま。
		$this->assertStringContainsString( '0 registered', $html );
		$this->assertStringNotContainsString( 'Inactive', $html, 'null must not be shown as "Inactive"' );
	}
}
