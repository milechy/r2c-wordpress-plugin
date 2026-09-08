<?php
/**
 * R2C_AI_Concierge_Ajax::post_string() — the single choke point every raw $_POST read in
 * this class now goes through (introduced after a code review found that
 * sanitize_email()/sanitize_hex_color() have no built-in guard against
 * non-scalar input, unlike sanitize_text_field()/sanitize_textarea_field(),
 * which WordPress core already hardens; a hand-crafted `email[]=x` or
 * `primary_color[]=x` submission would otherwise reach strlen()/preg_match()
 * with an array and fatal with a TypeError on PHP 8+).
 *
 * See test-ajax-connect.php's and test-ajax-save-settings-fields.php's
 * "submitted as array" tests for the same guarantee proven end-to-end
 * through the actual AJAX handlers, not just this isolated helper.
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxPostStringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_post_string( $key ) {
		$ref = new ReflectionMethod( '\R2C_AI_Concierge_Ajax', 'post_string' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, array( $key ) );
	}

	public function test_absent_key_returns_empty_string() {
		$this->assertSame( '', $this->call_post_string( 'missing' ) );
	}

	public function test_string_value_is_unslashed_and_returned() {
		Functions\when( 'wp_unslash' )->alias(
			function ( $value ) {
				return str_replace( '\\\'', "'", $value );
			}
		);
		$_POST['field'] = "O\\'Brien";

		$this->assertSame( "O'Brien", $this->call_post_string( 'field' ) );
	}

	/**
	 * The core case this helper exists for: a hand-crafted `field[]=x`
	 * submission must come back as '' (treated the same as "absent"),
	 * never as the raw array — that array would otherwise reach a
	 * string-only sanitizer downstream.
	 */
	public function test_array_value_returns_empty_string_rather_than_the_array() {
		Functions\expect( 'wp_unslash' )->never();
		$_POST['field'] = array( 'a', 'b' );

		$this->assertSame( '', $this->call_post_string( 'field' ) );
	}
}
