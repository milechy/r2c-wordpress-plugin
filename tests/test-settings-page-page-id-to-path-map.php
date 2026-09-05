<?php
/**
 * R2C_Settings_Page::page_id_to_path_map() / excludable_post_type_patterns()
 * — the lookup tables behind the "Add a specific page" / "Add a post type"
 * quick-add buttons (FR-10).
 *
 * ★Regression guard for a real bug found while writing this suite★
 * get_permalink()/get_post_type_archive_link() fall back to a
 * `?page_id={id}` / `?post_type={name}` query string whenever the site's
 * permalink structure is "Plain" — the state every fresh WordPress install
 * starts in, before an admin ever visits Settings → Permalinks. Under that
 * fallback, wp_parse_url( ..., PHP_URL_PATH ) returns just "/" for every
 * single page and post type — indistinguishable from one another. Before
 * the fix, that "/" (or "/*" for post types) was used as the exclusion
 * pattern regardless of which page/post type was actually picked, so
 * clicking "Add" for ANY page on such a site would hide the widget from
 * the entire site, not the one page selected. Both helpers now skip any
 * entry whose only resolvable path is "/", and
 * render_excluded_pages_section() (see the E2E suite's
 * excluded-pages-quick-add.spec.js) shows an explanatory note instead of
 * the pickers when nothing is left to offer.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-settings-page.php';

class SettingsPagePageIdToPathMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test-only stand-in for the real wp_parse_url, which just wraps this with a filter.
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/\\' ); // test-only stand-in matching real untrailingslashit's trailing-slash-strip behaviour.
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_page_id_to_path_map() {
		$ref = new ReflectionMethod( '\R2C_Settings_Page', 'page_id_to_path_map' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, array() );
	}

	public function test_pretty_permalinks_produce_a_distinct_path_per_page() {
		Functions\when( 'get_posts' )->justReturn( array( 5, 7 ) );
		Functions\when( 'get_permalink' )->alias(
			function ( $id ) {
				return 5 === $id ? 'https://site.example/about/' : 'https://site.example/contact/';
			}
		);

		$map = $this->call_page_id_to_path_map();

		$this->assertSame(
			array(
				5 => '/about/',
				7 => '/contact/',
			),
			$map
		);
	}

	public function test_plain_permalinks_yield_no_page_entries_rather_than_a_colliding_root_pattern() {
		Functions\when( 'get_posts' )->justReturn( array( 5, 7 ) );
		Functions\when( 'get_permalink' )->alias(
			function ( $id ) {
				return 'https://site.example/?page_id=' . $id;
			}
		);

		$this->assertSame( array(), $this->call_page_id_to_path_map() );
	}

	/* ---- excludable_post_type_patterns(): same root cause ---- */

	private function call_excludable_post_type_patterns() {
		$ref = new ReflectionMethod( '\R2C_Settings_Page', 'excludable_post_type_patterns' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, array() );
	}

	private function stub_one_public_post_type( $name, $label, $archive_link ) {
		$post_type_object               = new \stdClass();
		$post_type_object->name         = $name;
		$post_type_object->labels       = new \stdClass();
		$post_type_object->labels->name = $label;

		Functions\when( 'get_post_types' )->justReturn( array( $name => $post_type_object ) );
		Functions\when( 'get_post_type_archive_link' )->justReturn( $archive_link );
	}

	public function test_pretty_permalinks_produce_a_wildcard_scoped_to_the_post_type() {
		$this->stub_one_public_post_type( 'product', 'Products', 'https://site.example/shop/' );

		$patterns = $this->call_excludable_post_type_patterns();

		$this->assertSame( array( '/shop/*' => 'Products' ), $patterns );
	}

	public function test_plain_permalinks_yield_no_post_type_entries_rather_than_a_site_wide_wildcard() {
		$this->stub_one_public_post_type( 'product', 'Products', 'https://site.example/?post_type=product' );

		$this->assertSame( array(), $this->call_excludable_post_type_patterns() );
	}
}
