<?php
/**
 * R2C_Widget::render()'s data-* attribute logic (placement_attributes() is
 * private, so this exercises it the same way production traffic does — via
 * the public render() entry point and its captured output).
 *
 * Matching R2C's own defaults (bottom-right, 24px) must emit no attributes
 * at all — same convention as get_embed_code() in the parent repo.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-widget.php';

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
					case \R2C_Options::API_KEY:
						return 'connected-key';
					case \R2C_Options::TENANT_ID:
						return 't_abc';
					case \R2C_Options::CACHED_THEME:
						return $cached_theme;
					default:
						return $default;
				}
			}
		);
	}

	public function test_default_theme_emits_no_placement_attributes() {
		$this->stub_options( array() );

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

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

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-position="bottom-left"', $output );
		$this->assertStringContainsString( 'data-offset-x="100"', $output );
		$this->assertStringContainsString( 'data-offset-y="50"', $output );
		$this->assertStringContainsString( 'data-accent-color="#112233"', $output );
	}

	public function test_out_of_range_offset_is_ignored() {
		$this->stub_options( array( 'offset_x' => 500 ) );

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'data-offset-x', $output );
	}

	public function test_invalid_color_is_ignored() {
		$this->stub_options( array( 'primary_color' => 'not-a-color' ) );

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'data-accent-color', $output );
	}

	public function test_renders_nothing_when_disconnected() {
		Functions\when( 'get_option' )->justReturn( '' );

		ob_start();
		\R2C_Widget::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
