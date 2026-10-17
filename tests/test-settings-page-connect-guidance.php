<?php
/**
 * WP-UX-05: the guidance a shop owner reads on the connect screen — the two
 * entrances (new / already under contract), the free-plan conditions shown
 * before the consent checkbox, the login steps after connecting, and the
 * "disconnect is not cancel" warning.
 *
 * These are copy/structure tests on purpose: each one fails if the sentence
 * that stops a real complaint ("I disconnected but I'm still being billed",
 * "I paid already but a second free account got created") is removed.
 *
 * Also guards the two wordpress.org rules this screen sits closest to:
 * GL#7 (rendering the disconnected screen talks to nobody) and GL#5 (the
 * free-plan text is a description, never an "upgrade to unlock" prompt).
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

class SettingsPageConnectGuidanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_bloginfo' )->justReturn( 'owner@example.com' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$ref = new \ReflectionProperty( '\R2C_AI_Concierge_Settings_Page', 'page_id_to_path_map_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		parent::tearDown();
	}

	private function render_private( $method ) {
		$ref = new ReflectionMethod( '\R2C_AI_Concierge_Settings_Page', $method );
		$ref->setAccessible( true );
		ob_start();
		$ref->invoke( null );
		return ob_get_clean();
	}

	private function disconnected_html() {
		// GL#7: rendering this screen must never call R2C.
		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();
		return $this->render_private( 'render_disconnected' );
	}

	/* ---- ② 入口の2択 ---- */

	public function test_disconnected_screen_offers_both_entrances() {
		$html = $this->disconnected_html();

		$this->assertStringContainsString( 'id="r2c-entry-new"', $html );
		$this->assertStringContainsString( 'id="r2c-entry-existing"', $html );
		// 既存の接続フォーム/手動キーフォームの id は E2E と JS が使っている。
		$this->assertStringContainsString( 'id="r2c-connect-form"', $html );
		$this->assertStringContainsString( 'id="r2c-manual-form"', $html );
	}

	public function test_existing_customer_entrance_tells_them_how_to_get_a_key() {
		$html = $this->disconnected_html();

		$existing = substr( $html, (int) strpos( $html, 'id="r2c-entry-existing"' ) );
		$this->assertStringContainsString( 'Issue a connection key for WordPress', $existing );
		$this->assertStringContainsString( 'https://admin.r2c.biz/copilot-preview', $existing );
		// キー入力欄は、この入口の中にある(別の場所に取り残されていない)。
		$this->assertStringContainsString( 'id="r2c-manual-key"', $existing );
	}

	/**
	 * 契約者が「無料で始める」を押すと2つ目のテナントができる。押す前に
	 * 気づける位置(新規側の入口の中)に注意が要る。
	 */
	public function test_new_entrance_warns_existing_customers_not_to_use_the_button() {
		$html = $this->disconnected_html();

		$new = substr( $html, (int) strpos( $html, 'id="r2c-entry-new"' ), (int) strpos( $html, 'id="r2c-entry-existing"' ) - (int) strpos( $html, 'id="r2c-entry-new"' ) );
		$this->assertStringContainsString( 'This button creates a new free account', $new );
	}

	/* ---- ① 無料プランの条件 ---- */

	public function test_free_plan_conditions_are_shown_before_the_consent_checkbox() {
		$html = $this->disconnected_html();

		$consent_at = strpos( $html, 'id="r2c-consent"' );
		$this->assertNotFalse( $consent_at );
		$before = substr( $html, 0, $consent_at );

		$this->assertStringContainsString( 'Ads and R2C branding appear on the chat widget', $before );
		$this->assertStringContainsString( '200 conversations per month', $before );
		$this->assertStringContainsString( 'the 1st of the next month', $before );
		$this->assertStringContainsString( 'shared learning', $before );
		$this->assertStringContainsString( 'returns to this free plan', $before );
		$this->assertStringContainsString( 'href="https://r2c.biz/lp/"', $before );
	}

	/**
	 * GL#5: 条件の「説明」であって、支払いで機能をロックする文言ではない。
	 */
	public function test_free_plan_text_never_reads_as_a_feature_lock() {
		$html = strtolower( $this->disconnected_html() );

		foreach ( array( 'upgrade to', 'unlock', 'locked', 'pro plan', 'trial', 'premium' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html, "'$forbidden' must not appear on the connect screen (GL#5)." );
		}
	}

	/** GL#10: バッジ/広告帯はサービス側(widget.js)だけ。ここは文章での説明のみ。 */
	public function test_connect_screen_renders_no_badge_or_ad_markup() {
		$html = strtolower( $this->disconnected_html() );

		$this->assertStringNotContainsString( 'powered by', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	/* ---- ③ ログイン方法 ---- */

	public function test_next_steps_explain_the_invitation_email_login() {
		$html = $this->render_private( 'render_next_steps' );

		$this->assertStringContainsString( 'invitation email', $html );
		$this->assertStringContainsString( 'set a password', $html );
		$this->assertStringContainsString( 'spam folder', $html );
		// 既存の2リンクは残っている。
		$this->assertStringContainsString( 'Register FAQs', $html );
		$this->assertStringContainsString( 'View conversations', $html );
	}

	/* ---- WP-UX-09: 招待メールの再送ボタン ---- */

	public function test_next_steps_have_a_resend_invitation_button_without_any_remote_call() {
		// 画面を開いただけでは R2C に話しかけない(押されたときだけ)。
		Functions\expect( 'wp_remote_request' )->never();

		$html = $this->render_private( 'render_next_steps' );

		$this->assertStringContainsString( 'id="r2c-resend-invite-button"', $html );
		$this->assertStringContainsString( 'Resend the invitation email', $html );
		$this->assertStringContainsString( 'id="r2c-resend-invite-status"', $html );
	}

	/* ---- ④ 接続解除 ≠ 解約 ---- */

	public function test_disconnect_confirmation_says_billing_is_not_stopped() {
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( array() );

		$localized = null;
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = $data;
				return true;
			}
		);

		\R2C_AI_Concierge_Settings_Page::enqueue_assets( 'settings_page_r2c-ai-concierge' );

		$this->assertIsArray( $localized );
		$confirm = $localized['i18n']['confirmDisconnect'];
		$this->assertStringContainsString( 'does not cancel your contract', $confirm );
		$this->assertStringContainsString( 'stop billing', $confirm );
		$this->assertStringContainsString( 'I want to cancel', $confirm );
		// 既存の安心材料(データは消えない)も残す。
		$this->assertStringContainsString( 'will not be deleted', $confirm );
	}

	/* ---- ⑤ 翻訳(NFR-07): 画面に出す英語ソースは、すべて ja.po / .pot にある ---- */

	/**
	 * この画面の文言の1つでも翻訳の入れ忘れがあると、日本語の店主にだけ英語が
	 * 混ざる。画面を組み立てる関数のソースからリテラルを拾って突き合わせる。
	 */
	public function test_every_connect_screen_string_is_translated_in_ja_po_and_pot() {
		$methods = array( 'render_entry_new', 'render_entry_existing', 'render_next_steps', 'enqueue_assets' );
		$source  = '';
		foreach ( $methods as $method ) {
			$ref    = new ReflectionMethod( '\R2C_AI_Concierge_Settings_Page', $method );
			$lines  = file( $ref->getFileName() );
			$body   = array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 );
			// enqueue_assets() も他画面の既存文言(翻訳は別タスク)を含むので、今回足した確認文だけ見る。
			if ( 'enqueue_assets' === $method ) {
				$body = preg_grep( '/confirm/', $body );
			}
			$source .= implode( '', $body );
		}
		preg_match_all( "/(?:esc_html_e|esc_html__|__)\\(\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $source, $m );
		$msgids = array_unique( array_map( 'stripslashes', $m[1] ) );
		$this->assertGreaterThan( 15, count( $msgids ), 'The source scan found suspiciously few strings.' );

		$po  = file_get_contents( dirname( __DIR__ ) . '/languages/ja.po' );
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/r2c-ai-concierge.pot' );
		foreach ( $msgids as $msgid ) {
			// PO 側の表記(" は \" )に直して、ja.po は msgstr が空でないことまで見る。
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
