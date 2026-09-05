<?php
/**
 * R2C_Settings_Page::page_id_to_path_map() — the lookup table behind the
 * "Add a specific page" quick-add button (FR-10). It has no test anywhere,
 * unit or E2E: the E2E suite's WordPress install never registers a second
 * page beyond the default "Sample Page", and no spec exercises this button.
 *
 * This file exists specifically to pin down a suspected bug found while
 * reading the code rather than filling an arbitrary gap: get_permalink()
 * for a page falls back to `?page_id={id}` whenever the site's permalink
 * structure is "Plain" (WordPress core's own get_page_link(), not anything
 * this plugin controls) — and wp_parse_url( ..., PHP_URL_PATH ) on that URL
 * returns just "/", not a path that identifies the page. See the second
 * test below for the consequence.
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

	/**
	 * ★Known bug, pinned rather than silently accepted★ On a site still
	 * using WordPress's default "Plain" permalink structure (the state
	 * every fresh WP install starts in, before an admin visits
	 * Settings → Permalinks and picks something else), get_permalink() for
	 * *every* page returns `?page_id={id}` — a query string, not a path.
	 * wp_parse_url( ..., PHP_URL_PATH ) on that URL returns "/" for every
	 * single page, so page_id_to_path_map() maps every page to the same "/"
	 * entry. Clicking "Add a specific page" for ANY page on such a site
	 * appends the pattern "/" to the excluded-pages textarea — which, once
	 * saved, would hide the widget from the entire site rather than the one
	 * page the admin selected. This is a real, reachable bug in the FR-10
	 * quick-add helper, not a hypothetical: "Plain" is WordPress's own
	 * out-of-the-box default. See the accompanying risk report for the
	 * recommended fix (fall back to the page's slug, or refuse to add a
	 * pattern that doesn't distinguish the page from the site root).
	 */
	public function test_plain_permalinks_collapse_every_page_to_the_same_root_pattern() {
		Functions\when( 'get_posts' )->justReturn( array( 5, 7 ) );
		Functions\when( 'get_permalink' )->alias(
			function ( $id ) {
				return 'https://site.example/?page_id=' . $id;
			}
		);

		$map = $this->call_page_id_to_path_map();

		$this->assertSame(
			array(
				5 => '/',
				7 => '/',
			),
			$map,
			'This assertion documents the current (buggy) behaviour under Plain permalinks — see the doc comment above.'
		);
	}

	/* ---- excludable_post_type_patterns(): same root cause, worse pattern ---- */

	private function call_excludable_post_type_patterns() {
		$ref = new ReflectionMethod( '\R2C_Settings_Page', 'excludable_post_type_patterns' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, array() );
	}

	private function stub_one_public_post_type( $name, $label, $archive_link ) {
		$post_type_object                = new \stdClass();
		$post_type_object->name          = $name;
		$post_type_object->labels        = new \stdClass();
		$post_type_object->labels->name  = $label;

		Functions\when( 'get_post_types' )->justReturn( array( $name => $post_type_object ) );
		Functions\when( 'get_post_type_archive_link' )->justReturn( $archive_link );
	}

	public function test_pretty_permalinks_produce_a_wildcard_scoped_to_the_post_type() {
		$this->stub_one_public_post_type( 'product', 'Products', 'https://site.example/shop/' );

		$patterns = $this->call_excludable_post_type_patterns();

		$this->assertSame( array( '/shop/*' => 'Products' ), $patterns );
	}

	/**
	 * ★Same root cause as page_id_to_path_map(), worse result★ Under Plain
	 * permalinks, get_post_type_archive_link() also falls back to a query
	 * string (`?post_type=xxx`), so wp_parse_url PHP_URL_PATH again returns
	 * "/". Here that becomes the pattern "/*" (untrailingslashit('/') is ''
	 * , then '/*' is appended) — a wildcard that plausibly matches every
	 * path on the site, not just this post type's archive. Pinned for the
	 * same reason as the page-picker test above.
	 */
	public function test_plain_permalinks_produce_a_site_wide_wildcard_pattern() {
		$this->stub_one_public_post_type( 'product', 'Products', 'https://site.example/?post_type=product' );

		$patterns = $this->call_excludable_post_type_patterns();

		$this->assertSame(
			array( '/*' => 'Products' ),
			$patterns,
			'This assertion documents the current (buggy) behaviour under Plain permalinks — see the doc comment above.'
		);
	}
}
