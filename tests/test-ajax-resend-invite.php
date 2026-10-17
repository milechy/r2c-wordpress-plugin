<?php
/**
 * WP-UX-09: R2C_AI_Concierge_Ajax::handle_resend_invite() — the "resend the
 * invitation email" button on the connected screen.
 *
 * The owner who never received the invitation cannot log in to R2C, so
 * every outcome here must say what to do next. Also pins the rules this
 * button sits closest to: nonce + manage_options (guard), never sending an
 * address from the plugin (R2C decides the recipient), and never talking to
 * R2C while disconnected (GL#7).
 */

namespace R2C\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-options.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-r2c-ai-concierge-ajax.php';

class AjaxResendInviteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new \R2CTestJsonExit( true, $data );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status_code = null ) {
				throw new \R2CTestJsonExit( false, $data, $status_code );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function connect_with_key( $key = 'connected-key' ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $key ) {
				return \R2C_AI_Concierge_Options::API_KEY === $name ? $key : $default;
			}
		);
	}

	private function remote_responds( $status, $body ) {
		Functions\when( 'wp_remote_request' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	private function run_handler() {
		try {
			\R2C_AI_Concierge_Ajax::handle_resend_invite();
		} catch ( \R2CTestJsonExit $e ) {
			return $e;
		}
		$this->fail( 'expected the handler to halt via wp_send_json_*' );
	}

	public function test_the_action_is_registered_for_logged_in_admins_only() {
		Actions\expectAdded( 'wp_ajax_r2c_ai_concierge_resend_invite' )->once();
		// 'nopriv' 版を登録しない: ログインしていない人が押せる経路を作らない。
		Actions\expectAdded( 'wp_ajax_nopriv_r2c_ai_concierge_resend_invite' )->never();

		\R2C_AI_Concierge_Ajax::init();
		$this->addToAssertionCount( 1 );
	}

	public function test_non_admin_is_rejected_with_403_before_any_remote_call() {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_remote_request' )->never();

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertSame( 403, $e->status_code );
	}

	/** GL#7: 未接続では R2C に1回も通信しない(偽造リクエストでも)。 */
	public function test_not_connected_makes_no_remote_call() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'wp_remote_request' )->never();

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertSame( 'Not connected.', $e->data['message'] );
	}

	/** 宛先は R2C が決める。プラグインはメールアドレスを送らず、POST の値も使わない。 */
	public function test_it_posts_only_the_api_key_and_never_sends_an_address() {
		$this->connect_with_key( 'k_abc' );
		$_POST['email'] = 'attacker@example.com';

		$captured = null;
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = array( $url, $args );
				return array( 'fake' => 'response' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"x","email_masked":"ta***@example.com"}' );

		$this->run_handler();
		unset( $_POST['email'] );

		$this->assertSame( 'https://api.r2c.biz/v1/public/wp/resend-invite', $captured[0] );
		$this->assertSame( 'POST', $captured[1]['method'] );
		$this->assertSame( 'k_abc', $captured[1]['headers']['x-api-key'] );
		$this->assertArrayNotHasKey( 'body', $captured[1] );
	}

	public function test_success_names_the_masked_address_and_mentions_the_spam_folder() {
		$this->connect_with_key();
		$this->remote_responds( 200, '{"message":"x","email_masked":"ta***@example.com"}' );

		$e = $this->run_handler();

		$this->assertTrue( $e->success );
		$this->assertStringContainsString( 'ta***@example.com', $e->data['message'] );
		$this->assertStringContainsString( 'spam folder', $e->data['message'] );
	}

	public function test_success_without_an_address_in_the_response_still_says_it_was_sent() {
		$this->connect_with_key();
		$this->remote_responds( 200, '{"message":"x"}' );

		$e = $this->run_handler();

		$this->assertTrue( $e->success );
		$this->assertStringContainsString( 'We sent an email', $e->data['message'] );
		$this->assertStringContainsString( 'spam folder', $e->data['message'] );
	}

	/** 即時に届く/反映されると読める約束をしない(メール到着は R2C の外の事情)。 */
	public function test_success_makes_no_promise_of_instant_delivery() {
		$this->connect_with_key();
		$this->remote_responds( 200, '{"email_masked":"ta***@example.com"}' );

		$e = $this->run_handler();

		foreach ( array( 'immediately', 'instantly', 'right away', 'within a minute' ) as $promise ) {
			$this->assertStringNotContainsString( $promise, strtolower( $e->data['message'] ) );
		}
	}

	public function test_429_says_to_wait_about_an_hour() {
		$this->connect_with_key();
		$this->remote_responds( 429, '{"error":"rate_limit_exceeded","message":"x"}' );

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertSame( 'An email was just sent. Please wait about an hour and try again.', $e->data['message'] );
	}

	public function test_404_points_to_the_usual_login_or_support() {
		$this->connect_with_key();
		$this->remote_responds( 404, '{"error":"invite_recipient_not_found","message":"x"}' );

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertStringContainsString( 'connected with a connection key', $e->data['message'] );
		$this->assertStringContainsString( 'contact R2C support', $e->data['message'] );
	}

	public function test_502_says_to_retry_later_then_contact_support() {
		$this->connect_with_key();
		$this->remote_responds( 502, '{"error":"invite_send_failed","message":"x"}' );

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertStringContainsString( 'try again in about an hour', $e->data['message'] );
		$this->assertStringContainsString( 'contact R2C support', $e->data['message'] );
	}

	public function test_401_says_the_connection_is_no_longer_valid() {
		$this->connect_with_key();
		$this->remote_responds( 401, '{"error":"invalid_api_key","message":"x"}' );

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertStringContainsString( 'no longer valid', $e->data['message'] );
	}

	/** 503/500 など想定外の失敗も、成功に見せず、次の行動つきで返す。 */
	public function test_other_server_errors_are_reported_as_a_failure_with_a_next_step() {
		$this->connect_with_key();
		$this->remote_responds( 503, '{"error":"service_unavailable","message":"x"}' );

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertStringContainsString( 'Please try again in a moment', $e->data['message'] );
	}

	public function test_transport_failure_shows_the_unreachable_message() {
		$this->connect_with_key();
		$error = new \WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_request' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) use ( $error ) {
				return $thing === $error;
			}
		);

		$e = $this->run_handler();

		$this->assertFalse( $e->success );
		$this->assertSame( 'Unable to reach R2C right now. Please try again in a moment.', $e->data['message'] );
	}

	/** NFR-07: ボタンと結果の文言は、すべて ja.po / .pot にある(日本語の店主に英語を出さない)。 */
	public function test_every_resend_string_is_translated_in_ja_po_and_pot() {
		$source = '';
		foreach ( array( 'handle_resend_invite', 'resend_invite_error_message' ) as $method ) {
			$ref    = new \ReflectionMethod( '\R2C_AI_Concierge_Ajax', $method );
			$lines  = file( $ref->getFileName() );
			$source .= implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
		}
		preg_match_all( "/__\\(\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $source, $m );
		$msgids = array_unique( array_map( 'stripslashes', $m[1] ) );
		// 画面側(ボタン名・送信中)。
		$msgids[] = 'Resend the invitation email';
		$msgids[] = 'Sending…';
		$this->assertGreaterThan( 6, count( $msgids ), 'The source scan found suspiciously few strings.' );

		$po  = file_get_contents( dirname( __DIR__ ) . '/languages/ja.po' );
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/r2c-ai-concierge.pot' );
		foreach ( $msgids as $msgid ) {
			$escaped = str_replace( '"', '\\"', $msgid );
			$this->assertMatchesRegularExpression(
				'/^msgid "' . preg_quote( $escaped, '/' ) . '"\nmsgstr "(?!")[^\n]+"$/m',
				$po,
				"Not translated in ja.po: $msgid"
			);
			$this->assertStringContainsString( 'msgid "' . $escaped . '"', $pot, "Missing from the .pot: $msgid" );
		}
	}
}
