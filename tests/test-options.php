<?php
/**
 * R2C_Options — every other test file exercises this class only indirectly,
 * through whichever get_option()/update_option() stub each of them happens
 * to set up. Nothing tests R2C_Options' own logic directly: is_connected()'s
 * truthiness rule, clear_connection()'s completeness, the TTL floor on the
 * two transients, or get_cached_theme()'s defensive type check.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-options.php';

class OptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_connected_true_with_a_real_looking_key() {
		Functions\when( 'get_option' )->justReturn( 'r2c_live_abcdef123456' );
		$this->assertTrue( \R2C_Options::is_connected() );
	}

	public function test_is_connected_false_with_no_key_stored() {
		Functions\when( 'get_option' )->justReturn( '' );
		$this->assertFalse( \R2C_Options::is_connected() );
	}

	/**
	 * PHP gotcha, documented rather than silently relied upon: the string
	 * "0" is falsy in PHP, so if an API key were ever literally "0" (never
	 * true for R2C's real key format, but nothing in this class enforces
	 * that), is_connected() would wrongly report "not connected". Pinning
	 * this as a known, currently-harmless limitation rather than leaving it
	 * as a silent surprise if key formats ever change.
	 */
	public function test_is_connected_has_a_known_false_negative_for_the_literal_string_zero() {
		Functions\when( 'get_option' )->justReturn( '0' );
		$this->assertFalse( \R2C_Options::is_connected() );
	}

	public function test_set_connection_persists_all_three_fields_without_autoload() {
		$calls = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$calls ) {
				$calls[ $name ] = array( $value, $autoload );
				return true;
			}
		);

		\R2C_Options::set_connection( 'key_abc', 't_123', 'https://site.example/' );

		$this->assertSame( array( 'key_abc', false ), $calls[ \R2C_Options::API_KEY ] );
		$this->assertSame( array( 't_123', false ), $calls[ \R2C_Options::TENANT_ID ] );
		$this->assertSame( array( 'https://site.example/', false ), $calls[ \R2C_Options::SITE_ORIGIN ] );
	}

	/**
	 * Kept in sync with uninstall.php's raw delete_option() list by hand —
	 * this test is the tripwire for that sync: if a new option constant is
	 * added to clear_connection() but forgotten in uninstall.php (or vice
	 * versa), this at least proves what clear_connection() itself removes.
	 */
	public function test_clear_connection_removes_every_option_it_ever_writes() {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		\R2C_Options::clear_connection();

		sort( $deleted );
		$expected = array(
			\R2C_Options::API_KEY,
			\R2C_Options::CACHED_THEME,
			\R2C_Options::SITE_ORIGIN,
			\R2C_Options::TENANT_ID,
		);
		sort( $expected );
		$this->assertSame( $expected, $deleted );
	}

	public function test_get_cached_theme_defaults_to_empty_array_when_never_set() {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( array(), \R2C_Options::get_cached_theme() );
	}

	/**
	 * Defensive is_array() guard in get_cached_theme(): if the option value
	 * were ever corrupted into a non-array (a bad migration, a manual DB
	 * edit, a plugin conflict overwriting the row with a scalar), R2C_Widget
	 * must still get an array back rather than fataling on array access.
	 */
	public function test_get_cached_theme_falls_back_to_empty_array_for_corrupted_non_array_value() {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$this->assertSame( array(), \R2C_Options::get_cached_theme() );
	}

	public function test_pending_poll_token_ttl_uses_the_provided_hours() {
		$captured = array();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$captured ) {
				$captured = array( $name, $value, $ttl );
				return true;
			}
		);

		\R2C_Options::set_pending_poll_token( 'tok_1', 24 );

		$this->assertSame( array( \R2C_Options::PENDING_POLL, 'tok_1', 24 * HOUR_IN_SECONDS ), $captured );
	}

	/**
	 * max(1, ...) floor: a provisioning response that ever specified 0 (or
	 * a negative) hours must not create an instantly-expiring or
	 * already-expired transient — that would silently break the poll flow
	 * before the first poll even runs.
	 */
	public function test_pending_poll_token_ttl_is_floored_at_one_hour() {
		$captured = array();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$captured ) {
				$captured = $ttl;
				return true;
			}
		);

		\R2C_Options::set_pending_poll_token( 'tok_1', 0 );
		$this->assertSame( 1 * HOUR_IN_SECONDS, $captured );

		\R2C_Options::set_pending_poll_token( 'tok_1', -5 );
		$this->assertSame( 1 * HOUR_IN_SECONDS, $captured );
	}

	public function test_pending_challenge_ttl_is_floored_at_one_minute() {
		$captured = array();
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$captured ) {
				$captured = $ttl;
				return true;
			}
		);

		\R2C_Options::set_pending_challenge( 'chal_1', 0 );
		$this->assertSame( 1 * MINUTE_IN_SECONDS, $captured );
	}

	public function test_dismiss_notice_persists_and_is_reflected_by_is_notice_dismissed() {
		$stored = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);
		\R2C_Options::dismiss_notice();
		$this->assertTrue( $stored );

		Functions\when( 'get_option' )->justReturn( $stored );
		$this->assertTrue( \R2C_Options::is_notice_dismissed() );
	}
}
