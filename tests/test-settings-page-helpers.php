<?php
/**
 * R2C_AI_Concierge_Settings_Page's small pure(ish) private helpers — mask_api_key() and
 * plan_label() — plus an escaping regression test for render_status_summary().
 * None of these had any direct test; the settings screen is otherwise only
 * exercised end-to-end via Playwright, which never tries a malformed or
 * boundary-length value.
 *
 * The three private methods under test are invoked via Reflection rather
 * than through the full render() call graph — render_connected() also calls
 * render_next_steps()/render_widget_settings_form()/render_excluded_pages_section(),
 * which pull in get_post_types()/get_posts()/wp_dropdown_pages() and would
 * turn this into an unrelated integration test of that rendering machinery.
 * Reflection keeps this file scoped to exactly the logic it's testing —
 * the same tradeoff PHPUnit test suites for WP plugins commonly make for
 * "private but independently meaningful" static helpers.
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

class SettingsPageHelpersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		// page_id_to_path_map() memoizes into a private static property
		// (see test-settings-page-page-id-to-path-map.php's tearDown for
		// why this must be reset between tests in the same PHP process).
		$ref = new \ReflectionProperty( '\R2C_AI_Concierge_Settings_Page', 'page_id_to_path_map_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		parent::tearDown();
	}

	private function call_private_static( $method, array $args ) {
		$ref = new ReflectionMethod( '\R2C_AI_Concierge_Settings_Page', $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	/* ---- mask_api_key() ---- */

	public function test_mask_api_key_masks_a_realistic_key() {
		$masked = $this->call_private_static( 'mask_api_key', array( 'r2c_live_abcdef1234567890' ) );
		$this->assertSame( 'r2c_live_abc****', $masked );
	}

	/**
	 * Boundary at exactly 12 characters — the shortest input that still
	 * gets masked rather than hidden entirely.
	 */
	public function test_mask_api_key_at_exactly_twelve_characters_is_masked() {
		$masked = $this->call_private_static( 'mask_api_key', array( '123456789012' ) );
		$this->assertSame( '123456789012****', $masked );
	}

	public function test_mask_api_key_below_twelve_characters_returns_empty_string() {
		$masked = $this->call_private_static( 'mask_api_key', array( '12345678901' ) );
		$this->assertSame( '', $masked );
	}

	public function test_mask_api_key_empty_string_returns_empty_string() {
		$masked = $this->call_private_static( 'mask_api_key', array( '' ) );
		$this->assertSame( '', $masked );
	}

	/* ---- plan_label() ---- */

	public function test_plan_label_maps_every_known_plan() {
		Functions\when( '__' )->returnArg( 1 );

		$this->assertSame( 'Free plan (ad-supported)', $this->call_private_static( 'plan_label', array( 'free_ad' ) ) );
		$this->assertSame( 'Starter plan', $this->call_private_static( 'plan_label', array( 'starter' ) ) );
		$this->assertSame( 'Standard plan', $this->call_private_static( 'plan_label', array( 'standard' ) ) );
		$this->assertSame( 'Growth plan', $this->call_private_static( 'plan_label', array( 'growth' ) ) );
		$this->assertSame( 'Enterprise plan', $this->call_private_static( 'plan_label', array( 'enterprise' ) ) );
	}

	/**
	 * An unrecognized plan slug (a new plan added on R2C's side before this
	 * plugin's label map is updated) must fall back to showing the raw
	 * value rather than an empty string or a fatal array-key error.
	 */
	public function test_plan_label_falls_back_to_raw_value_for_an_unknown_plan() {
		Functions\when( '__' )->returnArg( 1 );
		$this->assertSame( 'some_future_plan', $this->call_private_static( 'plan_label', array( 'some_future_plan' ) ) );
	}

	/* ---- render_status_summary() escaping ---- */

	/**
	 * Escaping regression guard: every other test in this suite stubs
	 * esc_html()/esc_attr() as pass-through (returnArg), which would make an
	 * accidental `echo $body['tenant_id']` instead of
	 * `echo esc_html( $body['tenant_id'] )` invisible. This test uses a
	 * real htmlspecialchars()-based stub instead, so a future refactor that
	 * drops escaping on API-response-derived output actually fails a test —
	 * tenant_id/plan come back from an HTTP response this plugin does not
	 * fully control the shape of.
	 */
	public function test_render_status_summary_escapes_tenant_id_and_plan_from_the_api_response() {
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_html__' )->alias(
			function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only stand-in for the real esc_html_e(), which does exactly this.
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( '' ); // mask_api_key: no key stored, row omitted.

		$body = array(
			'tenant_id'         => '<script>alert(1)</script>',
			'is_active'         => true,
			'plan'              => '"><img src=x onerror=alert(2)>',
			'has_published_faq' => true,
		);

		ob_start();
		$this->call_private_static( 'render_status_summary', array( $body ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(2)>', $output );
	}

	/* ---- render_usage_status() (WP-18/D13) ---- */

	private function stub_common_i18n_passthroughs() {
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only stand-in.
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	/**
	 * render_usage_status() calls the real R2C_AI_Concierge_Api_Client::get_status()
	 * (a plain PHP static method Brain\Monkey cannot intercept), so these
	 * tests stub the WordPress functions that method itself calls through —
	 * the same boundary test-api-client-resilience.php stubs at.
	 */
	private function stub_status_endpoint_response( $status_code, $json_body ) {
		Functions\when( 'get_option' )->justReturn( 'fake-key' ); // R2C_AI_Concierge_Options::get_api_key()
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status_code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $json_body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only stand-in for the real wp_json_encode().
	}

	/**
	 * F-2/F-5/禁止48: 到達できた場合、5項目(会話数・学習件数・プラン・
	 * アバター稼働状態・FAQ件数・未対応件数)のみを表示し、USDセント原価等
	 * 余分な値を含めない。
	 */
	public function test_render_usage_status_shows_the_five_fields_on_success() {
		$this->stub_common_i18n_passthroughs();
		$this->stub_status_endpoint_response(
			200,
			array(
				'plan'                   => 'growth',
				'avatar_active'          => true,
				'faq_published_count'    => 5,
				'open_escalations_count' => 2,
				'weekly_summary'         => array(
					'sessions_this_week' => 12,
					'learned_this_week'  => 3,
				),
			)
		);

		ob_start();
		$this->call_private_static( 'render_usage_status', array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '12', $output );
		$this->assertStringContainsString( '3', $output );
		$this->assertStringContainsString( 'Growth plan', $output );
		$this->assertStringContainsString( 'Active', $output );
		$this->assertStringContainsString( '5', $output );
		$this->assertStringContainsString( '2', $output );
		$this->assertStringNotContainsString( 'cents', $output );
		$this->assertStringNotContainsString( 'cost_total', $output );
	}

	/**
	 * 母数0は0のまま表示する(禁止34)。0件を「効果なし」等に丸めない。
	 */
	public function test_render_usage_status_shows_zero_counts_as_zero() {
		$this->stub_common_i18n_passthroughs();
		$this->stub_status_endpoint_response(
			200,
			array(
				'plan'                   => 'starter',
				'avatar_active'          => false,
				'faq_published_count'    => 0,
				'open_escalations_count' => 0,
				'weekly_summary'         => array(
					'sessions_this_week' => 0,
					'learned_this_week'  => 0,
				),
			)
		);

		ob_start();
		$this->call_private_static( 'render_usage_status', array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Inactive', $output );
		$this->assertStringContainsString( '0', $output );
	}

	/**
	 * FR-40: API到達不能時は「取得できません」を表示し、直前の値を出し
	 * 続けたり無限ローディングを残したりしない。
	 */
	public function test_render_usage_status_shows_unavailable_message_on_failure() {
		$this->stub_common_i18n_passthroughs();
		Functions\when( 'get_option' )->justReturn( 'fake-key' );
		$error = new \WP_Error( 'http_request_failed', 'Connection timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		ob_start();
		$this->call_private_static( 'render_usage_status', array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unable to retrieve this information right now.', $output );
		$this->assertStringNotContainsString( 'form-table', $output );
	}

	/* ---- render_excluded_pages_section(): Plain-permalink guard ---- */

	/**
	 * When both quick-add lookups come back empty (this install has no
	 * pages/post types with a distinguishable path — see
	 * test-settings-page-page-id-to-path-map.php for why that happens under
	 * WordPress's default "Plain" permalink structure), the pickers must
	 * not render at all — an explanatory note takes their place instead.
	 */
	public function test_render_excluded_pages_section_shows_a_note_instead_of_pickers_when_no_page_or_post_type_has_a_distinguishable_path() {
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only stand-in.
			}
		);
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_textarea' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );

		// Both lookups empty: no post types, no pages. Plain permalinks
		// ('' === permalink_structure) so the Plain-specific note applies —
		// see the sibling test below for the "genuinely nothing to offer,
		// pretty permalinks already on" case.
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( '' );

		Functions\expect( 'wp_dropdown_pages' )->never();

		ob_start();
		$this->call_private_static( 'render_excluded_pages_section', array( array() ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'r2c-excluded-post-type-add', $output );
		$this->assertStringNotContainsString( 'r2c-excluded-page-picker', $output );
		$this->assertStringContainsString( 'pretty', $output );
	}

	/**
	 * The bug this fix corrects: both lookups can also come back empty on a
	 * site that already has pretty permalinks on, simply because it has no
	 * pages and no post-type archives yet — a completely different cause
	 * from Plain permalinks. Showing "this site is using Plain permalinks"
	 * in that case would be actively wrong, not just unhelpful.
	 */
	public function test_render_excluded_pages_section_shows_a_generic_note_when_pretty_permalinks_are_already_on() {
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only stand-in.
			}
		);
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_textarea' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );

		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( '/%postname%/' );

		Functions\expect( 'wp_dropdown_pages' )->never();

		ob_start();
		$this->call_private_static( 'render_excluded_pages_section', array( array() ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Plain', $output );
		$this->assertStringContainsString( 'no pages or post type archives', $output );
	}
}
