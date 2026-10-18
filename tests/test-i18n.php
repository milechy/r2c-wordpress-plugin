<?php
/**
 * WP-UX-19: the bundled Japanese .mo is only found automatically on
 * WordPress 6.8+ (the "Domain Path" header). Below that, R2C_AI_Concierge_I18n
 * must load it — otherwise a Japanese site on WP 6.0-6.7 shows English.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'R2C_AI_CONCIERGE_FILE' ) ) {
	define( 'R2C_AI_CONCIERGE_FILE', '/var/www/html/wp-content/plugins/r2c-ai-concierge/r2c-ai-concierge.php' );
}

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-i18n.php';

class I18nTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// once()/never() are checked by Mockery at tearDown; count them so
		// PHPUnit does not call these tests risky.
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_loads_the_bundled_translation_below_wp_6_8() {
		Functions\when( 'get_bloginfo' )->justReturn( '6.7.2' );
		Functions\when( 'plugin_basename' )->justReturn( 'r2c-ai-concierge/r2c-ai-concierge.php' );
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'r2c-ai-concierge', false, 'r2c-ai-concierge/languages' );

		\R2C_AI_Concierge_I18n::load_textdomain();
	}

	public function test_leaves_loading_to_wordpress_on_6_8_and_later() {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\expect( 'load_plugin_textdomain' )->never();

		\R2C_AI_Concierge_I18n::load_textdomain();
	}
}
