<?php
/**
 * R2C_AI_Concierge_Widget::render_tag()'s data-* attribute logic (placement_attributes() is
 * private, so this exercises it the same way production traffic does — via
 * the public render_tag() entry point and its return value).
 *
 * Matching R2C's own defaults (bottom-right, 24px) must emit no attributes
 * at all — same convention as get_embed_code() in the parent repo.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-widget.php';

class WidgetPlacementAttributesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_options( $cached_theme ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $cached_theme ) {
				switch ( $name ) {
					case \R2C_AI_Concierge_Options::API_KEY:
						return 'connected-key';
					case \R2C_AI_Concierge_Options::TENANT_ID:
						return 't_abc';
					case \R2C_AI_Concierge_Options::CACHED_THEME:
						return $cached_theme;
					default:
						return $default;
				}
			}
		);
	}

	public function test_default_theme_emits_no_placement_attributes() {
		$this->stub_options( array() );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringContainsString( 'data-tenant="t_abc"', $output );
		$this->assertStringNotContainsString( 'data-position', $output );
		$this->assertStringNotContainsString( 'data-offset', $output );
		$this->assertStringNotContainsString( 'data-accent-color', $output );
	}

	public function test_custom_theme_emits_matching_attributes() {
		$this->stub_options(
			array(
				'position'      => 'bottom-left',
				'offset_x'      => 100,
				'offset_y'      => 50,
				'primary_color' => '#112233',
			)
		);

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringContainsString( 'data-position="bottom-left"', $output );
		$this->assertStringContainsString( 'data-offset-x="100"', $output );
		$this->assertStringContainsString( 'data-offset-y="50"', $output );
		$this->assertStringContainsString( 'data-accent-color="#112233"', $output );
	}

	public function test_out_of_range_offset_is_ignored() {
		$this->stub_options( array( 'offset_x' => 500 ) );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringNotContainsString( 'data-offset-x', $output );
	}

	public function test_invalid_color_is_ignored() {
		$this->stub_options( array( 'primary_color' => 'not-a-color' ) );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringNotContainsString( 'data-accent-color', $output );
	}

	public function test_renders_nothing_when_disconnected() {
		Functions\when( 'get_option' )->justReturn( '' );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertSame( '', $output );
	}

	/**
	 * An API key can exist locally (is_connected() true) while tenant_id is
	 * somehow empty — e.g. a partially-completed manual connection, or a
	 * corrupted option row. render_tag() must still emit nothing rather than a
	 * script tag with an empty/garbage tenant id in its URL.
	 */
	public function test_renders_nothing_when_connected_but_tenant_id_is_empty() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( \R2C_AI_Concierge_Options::API_KEY === $name ) {
					return 'connected-key';
				}
				if ( \R2C_AI_Concierge_Options::TENANT_ID === $name ) {
					return '';
				}
				return $default;
			}
		);

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertSame( '', $output );
	}

	public function test_renders_nothing_in_wp_admin_even_when_connected() {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->stub_options(
			array(
				'position' => 'bottom-left',
			)
		);

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertSame( '', $output );
	}

	private function stub_options_with_key( $api_key, $tenant_id = 't_abc' ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $api_key, $tenant_id ) {
				switch ( $name ) {
					case \R2C_AI_Concierge_Options::API_KEY:
						return $api_key;
					case \R2C_AI_Concierge_Options::TENANT_ID:
						return $tenant_id;
					default:
						return $default;
				}
			}
		);
	}

	/**
	 * widget.js reads the key only from document.currentScript's
	 * `data-api-key` and sends it as `x-api-key`; without it every chat
	 * request is a 401.
	 */
	public function test_tag_includes_data_api_key() {
		$this->stub_options_with_key( 'r2c_live_key_123' );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringContainsString( 'data-api-key="r2c_live_key_123"', $output );
		$this->assertStringContainsString( 'data-tenant="t_abc"', $output );
	}

	public function test_data_api_key_value_is_escaped() {
		Functions\when( 'esc_attr' )->alias(
			function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		$this->stub_options_with_key( 'k"><script>alert(1)</script>' );

		$output = \R2C_AI_Concierge_Widget::render_tag();

		$this->assertStringContainsString( 'data-api-key="k&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $output );
		$this->assertStringNotContainsString( 'k"><script>', $output );
	}

	public function test_renders_nothing_when_tenant_id_set_but_api_key_empty() {
		$this->stub_options_with_key( '' );

		$this->assertSame( '', \R2C_AI_Concierge_Widget::render_tag() );
	}

	public function test_enqueue_registers_script_when_key_and_tenant_present() {
		$this->stub_options_with_key( 'r2c_live_key_123' );
		Functions\expect( 'wp_enqueue_script' )->once();

		\R2C_AI_Concierge_Widget::enqueue();

		// The Brain Monkey expectation above is verified in tearDown().
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_registers_nothing_when_api_key_empty() {
		$this->stub_options_with_key( '' );
		Functions\expect( 'wp_enqueue_script' )->never();

		\R2C_AI_Concierge_Widget::enqueue();

		// The Brain Monkey expectation above is verified in tearDown().
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_registers_nothing_when_tenant_id_empty() {
		$this->stub_options_with_key( 'r2c_live_key_123', '' );
		Functions\expect( 'wp_enqueue_script' )->never();

		\R2C_AI_Concierge_Widget::enqueue();

		// The Brain Monkey expectation above is verified in tearDown().
		$this->addToAssertionCount( 1 );
	}
}
