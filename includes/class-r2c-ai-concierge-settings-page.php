<?php
/**
 * The single admin screen this plugin has: Settings → R2C.
 *
 * ★No local persistence of position/offset/color/excluded-pages as
 * authority★ On every render, if connected, this fetches the current
 * values from R2C (R2C_AI_Concierge_Api_Client::get_settings) and displays those —
 * never a locally-remembered value pretending to be current (D9 / FR-23).
 * If that fetch fails, the settings form is not shown at all rather than
 * shown with stale or fabricated defaults (FR-24 / NFR-06).
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_AI_Concierge_Settings_Page {

	const SLUG = 'r2c-ai-concierge';

	// テナントの運用は R2C App(CopilotUI)側で行う(D10)。このプラグインは
	// 設置までが役割であり、FAQ登録・有人対応・課金はここに作らない(FR-28)。
	const APP_URL = 'https://admin.r2c.biz/copilot-preview';

	/**
	 * Per-request memoization of page_id_to_path_map(). enqueue_assets()
	 * (admin_enqueue_scripts) and render_excluded_pages_section() (inside
	 * render()) both call it on the same settings-page request, so this
	 * keeps get_posts() from running twice. Tests must reset it to null in
	 * tearDown() — PHPUnit runs every test in one PHP process by default,
	 * so a static value would otherwise leak across tests.
	 *
	 * @var array<int,string>|null
	 */
	private static $page_id_to_path_map_cache = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_options_page(
			__( 'R2C', 'r2c-ai-concierge' ),
			__( 'R2C', 'r2c-ai-concierge' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'r2c-ai-concierge-settings',
			R2C_AI_CONCIERGE_URL . 'assets/js/admin-settings.js',
			array(),
			R2C_AI_CONCIERGE_VERSION,
			true
		);
		wp_localize_script(
			'r2c-ai-concierge-settings',
			'r2cAiConciergeAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( R2C_AI_Concierge_Ajax::NONCE_ACTION ),
				// FR-10の「特定の固定ページ」選択肢: wp_dropdown_pages()はページIDしか
				// 渡せないため、パターン変換(パーマリンク→パス)はサーバ側でここにまとめて
				// 済ませ、JS側は選ばれたIDでこのマップを引くだけにする。
				'pagePaths' => self::page_id_to_path_map(),
				'i18n'      => array(
					'connecting'        => __( 'Connecting…', 'r2c-ai-concierge' ),
					'confirmDisconnect' => __( 'Are you sure you want to disconnect? Your conversation data and your R2C account will not be deleted. Disconnecting does not cancel your contract or stop billing. To cancel, open the R2C dashboard and tell the assistant "I want to cancel".', 'r2c-ai-concierge' ),
					'confirmButton'     => __( 'Click again to disconnect', 'r2c-ai-concierge' ),
					'sending'           => __( 'Sending…', 'r2c-ai-concierge' ),
					'saving'            => __( 'Saving…', 'r2c-ai-concierge' ),
					'saved'             => __( 'Saved.', 'r2c-ai-concierge' ),
					'nothingToChange'   => __( 'There is nothing to change.', 'r2c-ai-concierge' ),
					'networkError'      => __( 'Unable to reach the server. Please check your connection and try again.', 'r2c-ai-concierge' ),
				),
			)
		);
		wp_enqueue_style(
			'r2c-ai-concierge-settings',
			R2C_AI_CONCIERGE_URL . 'assets/css/admin-settings.css',
			array(),
			R2C_AI_CONCIERGE_VERSION
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap r2c-settings">';
		echo '<h1>' . esc_html__( 'R2C', 'r2c-ai-concierge' ) . '</h1>';

		if ( R2C_AI_Concierge_Options::is_connected() ) {
			self::render_connected();
		} else {
			self::render_disconnected();
		}

		echo '</div>';
	}

	/* 未接続 */

	private static function render_disconnected() {
		?>
		<div id="r2c-connect-panel">
			<p><?php esc_html_e( 'R2C is an AI concierge widget that automatically chats with your site\'s visitors. Connect using the button below to display the widget with no theme editing required.', 'r2c-ai-concierge' ); ?></p>

			<?php self::render_entry_new(); ?>
			<?php self::render_entry_existing(); ?>
		</div>
		<?php
	}

	/**
	 * 入口①: 初めての方。無料プランの条件は同意チェックの「前」に出す(WP-UX-05)。
	 * 機能ロックではなく条件の説明のみ(GL#5)。バッジ/広告の描画は widget.js 側
	 * (GL#10)なので、ここは文章で伝えるだけ。
	 */
	private static function render_entry_new() {
		?>
		<div id="r2c-entry-new" class="r2c-entry">
			<h2><?php esc_html_e( 'First time using R2C? Start for free', 'r2c-ai-concierge' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Already an R2C customer? Please use "I already have an R2C contract" below instead. This button creates a new free account.', 'r2c-ai-concierge' ); ?></p>

			<h3><?php esc_html_e( 'About the free plan', 'r2c-ai-concierge' ); ?></h3>
			<ul class="r2c-free-terms" style="list-style:disc;margin-left:20px;">
				<li><?php esc_html_e( 'Ads and R2C branding appear on the chat widget.', 'r2c-ai-concierge' ); ?></li>
				<li><?php esc_html_e( 'The widget pauses after 200 conversations per month and starts again on the 1st of the next month.', 'r2c-ai-concierge' ); ?></li>
				<li><?php esc_html_e( 'Conversation data is used for R2C\'s shared learning. This cannot be changed on the free plan.', 'r2c-ai-concierge' ); ?></li>
				<li><?php esc_html_e( 'If you move to a paid plan and cancel it later, your site returns to this free plan and the widget keeps running with ads.', 'r2c-ai-concierge' ); ?></li>
			</ul>
			<p><a href="https://r2c.biz/lp/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See plans and pricing', 'r2c-ai-concierge' ); ?></a></p>

			<form id="r2c-connect-form">
				<p>
					<label for="r2c-email"><?php esc_html_e( 'Email address', 'r2c-ai-concierge' ); ?></label><br />
					<input type="email" id="r2c-email" name="email" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" required />
				</p>
				<p>
					<label>
						<input type="checkbox" id="r2c-consent" name="consent" value="1" required />
						<?php
						printf(
							/* translators: 1: terms of service link, 2: privacy policy link */
							esc_html__( 'Connecting will send your site URL, site name, site language, WordPress/plugin versions, and the email address above to R2C. I agree to R2C\'s %1$s and %2$s.', 'r2c-ai-concierge' ),
							'<a href="https://r2c.biz/legal/terms.html" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'r2c-ai-concierge' ) . '</a>',
							'<a href="https://r2c.biz/legal/privacy.html" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'r2c-ai-concierge' ) . '</a>'
						);
						?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary" id="r2c-connect-button"><?php esc_html_e( 'Connect', 'r2c-ai-concierge' ); ?></button>
				</p>
				<p id="r2c-connect-status" role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php
	}

	/**
	 * 手動キー貼り付けフォーム(FR-05)。未接続画面の<details>内と、キー失効
	 * 画面(render_key_revoked)の2か所から同じ要素IDで出す — どちらの画面でも
	 * 同じJS(initManualForm)と同じAJAXハンドラで動かし、経路を増やさない。
	 */
	private static function render_manual_key_form() {
		?>
				<form id="r2c-manual-form" style="margin-top: 12px;">
					<p>
						<label for="r2c-manual-key"><?php esc_html_e( 'Connection key', 'r2c-ai-concierge' ); ?></label><br />
						<input type="text" id="r2c-manual-key" name="api_key" class="regular-text" autocomplete="off" />
					</p>
					<p>
						<button type="submit" class="button" id="r2c-manual-button"><?php esc_html_e( 'Connect with this key', 'r2c-ai-concierge' ); ?></button>
					</p>
					<p id="r2c-manual-status" role="status" aria-live="polite"></p>
				</form>
		<?php
	}

	/**
	 * 入口②: すでに契約中の方。メール照合の自動紐付けはしない(他人の契約に
	 * 繋がるのを防ぐため)。R2C 管理画面で発行した接続キーを貼る。
	 * <summary> は E2E(wp-admin.js)が開く目印として、この1つだけにしてある。
	 */
	private static function render_entry_existing() {
		?>
		<details id="r2c-entry-existing" class="r2c-entry" style="margin-top: 24px;">
			<summary><strong><?php esc_html_e( 'I already have an R2C contract (connect with a connection key)', 'r2c-ai-concierge' ); ?></strong></summary>
			<p style="margin-top: 12px;">
				<?php
				printf(
					/* translators: %s: link to the R2C dashboard */
					esc_html__( 'Open the %s and tell the assistant: "Issue a connection key for WordPress." Then paste the key into the box below.', 'r2c-ai-concierge' ),
					'<a href="' . esc_url( self::APP_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'R2C dashboard', 'r2c-ai-concierge' ) . '</a>'
				);
				?>
			</p>
			<?php self::render_manual_key_form(); ?>
		</details>
		<?php
	}

	/* 接続済み */

	/**
	 * R2C が保存済みキーに 401 を返したとき(キーが失効・再発行された)。
	 * 到達不能・5xx(一時的な不調。「しばらくしてから」で足りる)とは原因も
	 * 次の行動も違うため、別の文言・別の画面にする。このとき「接続済み」とは
	 * 表示しない — キーはもう使えず、ウィジェットも応答できていない。
	 * 再接続は未接続画面と同じ手動キー貼り付けフォーム(FR-05)で行う。
	 */
	private static function render_key_revoked() {
		echo '<p>' . esc_html__( 'Status: Connection is no longer valid', 'r2c-ai-concierge' ) . '</p>';
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The connection key is no longer valid. In the R2C dashboard, ask the assistant to "issue a WordPress connection key", then paste the code it shows into the box below to reconnect.', 'r2c-ai-concierge' )
		);
		self::render_manual_key_form();
		self::render_disconnect_button();
	}
	private static function render_connected() {
		$result = R2C_AI_Concierge_Api_Client::get_settings( R2C_AI_Concierge_Options::get_api_key() );

		if ( ! $result['ok'] && 401 === $result['status'] ) {
			self::render_key_revoked();
			return;
		}

		echo '<p>' . esc_html__( 'Status: Connected', 'r2c-ai-concierge' ) . '</p>';

		self::render_origin_mismatch_notice();
		self::render_next_steps();

		// プランの表示元はこの1回の取得に一本化する(設定の取得結果には載せない)。
		$status_result = R2C_AI_Concierge_Api_Client::get_status( R2C_AI_Concierge_Options::get_api_key() );
		self::render_usage_status( $status_result );

		if ( ! $result['ok'] ) {
			// FR-24: 取得できない値で「保存できるように見える」フォームを出さない。
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Unable to reach R2C right now. To change settings, please reopen this page in a moment.', 'r2c-ai-concierge' )
			);
			self::render_disconnect_button();
			return;
		}

		// ウィジェットは位置・色をタグの data 属性からしか読まない。R2C 側(管理画面)で
		// 変えた値がサイトに出るよう、画面を開いて取得できたときに取り込む。
		R2C_AI_Concierge_Options::cache_theme_from_settings( $result['body'] );

		self::render_status_summary( $result['body'], $status_result );
		self::render_widget_settings_form( $result['body'] );
		self::render_disconnect_button();
	}

	/**
	 * URL から「スキーム://ホスト[:ポート]」だけを取り出す(小文字化。既定ポートは省く)。
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$origin = $scheme . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) && ! ( 'http' === $scheme && 80 === (int) $parts['port'] ) && ! ( 'https' === $scheme && 443 === (int) $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * 接続したときのサイトのアドレスと、今のアドレスのオリジンが違うときだけ
	 * array( 接続時, 現在 ) を返す(WP-UX-18 d)。接続時の記録が無い古い接続は比べない。
	 * ローカルの値どうしの比較なので通信しない。
	 */
	private static function origin_mismatch() {
		$saved = self::origin_of( R2C_AI_Concierge_Options::get_site_origin() );
		if ( '' === $saved ) {
			return null;
		}
		$current = self::origin_of( home_url( '/' ) );
		return ( '' !== $current && $saved !== $current ) ? array( $saved, $current ) : null;
	}

	/**
	 * ドメイン変更・サイト複製(I-1 / I-2)。このままだとウィジェットが無言で止まる。
	 * 複製先で「接続を解除」すると、同じキーを使う本番も止まるため、その注意も添える。
	 */
	private static function render_origin_mismatch_notice() {
		$mismatch = self::origin_mismatch();
		if ( null === $mismatch ) {
			return;
		}
		printf(
			'<div class="notice notice-warning inline r2c-origin-mismatch"><p>%s</p></div>',
			sprintf(
				/* translators: 1: the site address when it was connected, 2: the current site address */
				esc_html__( 'The site address when you connected (%1$s) is different from the current address (%2$s). The chat will not appear as it is. If you copied this site, do not press "Disconnect" on this screen: it would also stop the original site. If you changed the address, open the R2C dashboard and tell the assistant "Add %2$s to the allowed domains".', 'r2c-ai-concierge' ),
				esc_html( $mismatch[0] ),
				esc_html( $mismatch[1] )
			)
		);
	}

	/**
	 * FR-12(接続状態・テナントID・プラン・稼働可否を表示)とFR-27(FAQ未登録警告
	 * は設定画面内に限定しサイト全体のadmin noticeにしない)。どちらもWP-13の
	 * GETをそのまま表示するだけで、ローカルに権威を持たせない(D9)。
	 */
	private static function render_status_summary( $body, $status_result = null ) {
		$tenant_id    = isset( $body['tenant_id'] ) ? $body['tenant_id'] : R2C_AI_Concierge_Options::get_tenant_id();
		$is_active    = ! empty( $body['is_active'] );
		$plan         = self::plan_from_status( $status_result );
		$status_label = $is_active ? __( 'Active', 'r2c-ai-concierge' ) : __( 'Inactive', 'r2c-ai-concierge' );
		$masked_key   = self::mask_api_key( R2C_AI_Concierge_Options::get_api_key() );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Status', 'r2c-ai-concierge' ); ?></th>
					<td><?php echo esc_html( $status_label ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Store ID', 'r2c-ai-concierge' ); ?></th>
					<td><code><?php echo esc_html( $tenant_id ); ?></code></td>
				</tr>
				<?php if ( '' !== $masked_key ) : ?>
				<tr>
					<th><?php esc_html_e( 'Connection key', 'r2c-ai-concierge' ); ?></th>
					<td><code><?php echo esc_html( $masked_key ); ?></code></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Plan', 'r2c-ai-concierge' ); ?></th>
					<td><?php echo esc_html( null === $plan ? __( 'Could not be retrieved', 'r2c-ai-concierge' ) : self::plan_label( $plan ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
		if ( ! $is_active ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Your R2C service is currently suspended. Please check the details in the R2C dashboard.', 'r2c-ai-concierge' )
			);
		}
		if ( empty( $body['has_published_faq'] ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
				esc_html__( 'No published FAQs have been registered yet. The widget will display, but it will not be able to answer questions.', 'r2c-ai-concierge' ),
				esc_url( self::APP_URL ),
				esc_html__( 'Register FAQs', 'r2c-ai-concierge' )
			);
		}
	}

	/**
	 * WP-18/D13(FR-34〜40): 「今週の状況」表示専用ブロック。取得は WP-17
	 * (GET /v1/public/wp/status)のみを使い、render_status_summary()が使う
	 * get_settings()のレスポンスとは混ぜない(この画面から呼ぶ操作ボタンは
	 * 一切置かない — D10を維持し、アバターON/OFF等のTier B操作はここに置かない)。
	 * 到達不能・タイムアウト時は「取得できません」を表示し、直前の値をそのまま
	 * 表示し続けたり無限ローディングを残したりしない(FR-40) — このプラグインは
	 * PHPのサーバサイドレンダリングのみでJSポーリングを持たないため、1回の
	 * リクエストで成功か失敗のどちらかが必ず確定する。
	 */
	private static function render_usage_status( $result = null ) {
		if ( null === $result ) {
			$result = R2C_AI_Concierge_Api_Client::get_status( R2C_AI_Concierge_Options::get_api_key() );
		}

		echo '<h2>' . esc_html__( 'This week', 'r2c-ai-concierge' ) . '</h2>';

		if ( ! $result['ok'] || ! is_array( $result['body'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Unable to retrieve this information right now.', 'r2c-ai-concierge' )
			);
			return;
		}

		$body        = $result['body'];
		$weekly      = isset( $body['weekly_summary'] ) && is_array( $body['weekly_summary'] ) ? $body['weekly_summary'] : array();
		$sessions    = self::status_count( $weekly, 'sessions_this_week' );
		$learned     = self::status_count( $weekly, 'learned_this_week' );
		$avatar      = array_key_exists( 'avatar_active', $body ) ? $body['avatar_active'] : null;
		$faq_count   = self::status_count( $body, 'faq_published_count' );
		$open_count  = self::status_count( $body, 'open_escalations_count' );
		$unavailable = __( 'Could not be retrieved', 'r2c-ai-concierge' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Conversations', 'r2c-ai-concierge' ); ?></th>
					<td>
						<?php
						if ( null === $sessions || null === $learned ) {
							echo esc_html( $unavailable );
						} else {
							printf(
								/* translators: 1: conversation count this week, 2: count of things the AI learned this week */
								esc_html__( '%1$d this week / %2$d things the AI learned this week', 'r2c-ai-concierge' ),
								(int) $sessions,
								(int) $learned
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Avatar', 'r2c-ai-concierge' ); ?></th>
					<td>
						<?php
						if ( null === $avatar ) {
							echo esc_html( $unavailable );
						} else {
							echo esc_html( $avatar ? __( 'Active', 'r2c-ai-concierge' ) : __( 'Inactive', 'r2c-ai-concierge' ) );
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'FAQs', 'r2c-ai-concierge' ); ?></th>
					<td>
						<?php
						if ( null === $faq_count ) {
							echo esc_html( $unavailable );
						} else {
							printf(
								/* translators: %d: number of published FAQs */
								esc_html__( '%d registered', 'r2c-ai-concierge' ),
								(int) $faq_count
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Unhandled inquiries', 'r2c-ai-concierge' ); ?></th>
					<td>
						<?php
						if ( null === $open_count ) {
							echo esc_html( $unavailable );
						} else {
							printf(
								/* translators: %d: number of unresolved inquiries */
								esc_html__( '%d unresolved', 'r2c-ai-concierge' ),
								(int) $open_count
							);
						}
						?>
						<a href="<?php echo esc_url( self::APP_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Respond in the R2C dashboard', 'r2c-ai-concierge' ); ?></a>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * /v1/public/wp/status の件数。サーバは取得できなかった項目を null で返す
	 * (WP-UX-17)ので、null・欠落は「0件」ではなく null(=取得できなかった)のまま扱う。
	 */
	private static function status_count( $source, $key ) {
		return ( is_array( $source ) && isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) ? (int) $source[ $key ] : null;
	}

	/**
	 * プラン表示の唯一の取得元は /v1/public/wp/status。取得に失敗した・プランが
	 * null のときは null(「無料プラン」などに化けさせない)。
	 */
	private static function plan_from_status( $status_result ) {
		if ( ! is_array( $status_result ) || empty( $status_result['ok'] ) || ! is_array( $status_result['body'] ) ) {
			return null;
		}
		$plan = isset( $status_result['body']['plan'] ) ? $status_result['body']['plan'] : null;
		return ( is_string( $plan ) && '' !== $plan ) ? $plan : null;
	}

	/**
	 * WP-4/NFR-05/D6: ローカル暗号化はしない代わりに、画面には常にマスク
	 * 済みでしか出さない(生キーをそのまま表示しない)。マスク規則は
	 * commerce-faq-tasks側 apiKeyUtils.ts の maskApiKey() と揃える
	 * (先頭12文字 + "****")— 表示形式が食い違うとサポート時に混乱するため。
	 */
	private static function mask_api_key( $api_key ) {
		if ( strlen( $api_key ) < 12 ) {
			return '';
		}
		return substr( $api_key, 0, 12 ) . '****';
	}

	private static function plan_label( $plan ) {
		$labels = array(
			'free_ad'    => __( 'Free plan (ad-supported)', 'r2c-ai-concierge' ),
			'starter'    => __( 'Starter plan', 'r2c-ai-concierge' ),
			'standard'   => __( 'Standard plan', 'r2c-ai-concierge' ),
			'growth'     => __( 'Growth plan', 'r2c-ai-concierge' ),
			'enterprise' => __( 'Enterprise plan', 'r2c-ai-concierge' ),
		);
		return isset( $labels[ $plan ] ) ? $labels[ $plan ] : $plan;
	}

	private static function render_next_steps() {
		// 招待メールは「プラグインから新規登録した人」にだけ送られる。接続キーを貼って既存の
		// アカウントに繋いだ人には届いていないので、案内も再送ボタンも出さない。
		$invitation = '';
		if ( ! R2C_AI_Concierge_Options::is_connected_with_key() ) {
			$invitation = sprintf(
				'<li>%s<br /><button type="button" class="button" id="r2c-resend-invite-button">%s</button> <span id="r2c-resend-invite-status" role="status" aria-live="polite"></span></li>',
				esc_html__( 'If you started with the free plan, an invitation email has been sent to you. Open it, set a password, and log in to the R2C dashboard. If it has not arrived, please check your spam folder.', 'r2c-ai-concierge' ),
				esc_html__( 'Resend the invitation email', 'r2c-ai-concierge' )
			);
		}

		printf(
			'<div class="notice notice-info r2c-next-steps"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px;">%s<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li><li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li></ul></div>',
			esc_html__( 'Next steps', 'r2c-ai-concierge' ),
			$invitation, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html__() only.
			esc_url( self::APP_URL ),
			esc_html__( 'Register FAQs', 'r2c-ai-concierge' ),
			esc_url( self::APP_URL ),
			esc_html__( 'View conversations', 'r2c-ai-concierge' )
		);
	}

	private static function render_widget_settings_form( $settings ) {
		$position      = isset( $settings['position'] ) ? $settings['position'] : 'bottom-right';
		$offset_x      = isset( $settings['offset_x'] ) ? (int) $settings['offset_x'] : 24;
		$offset_y      = isset( $settings['offset_y'] ) ? (int) $settings['offset_y'] : 24;
		$primary_color = isset( $settings['primary_color'] ) && $settings['primary_color'] ? $settings['primary_color'] : '';
		$excluded      = isset( $settings['excluded_page_patterns'] ) && is_array( $settings['excluded_page_patterns'] )
			? $settings['excluded_page_patterns']
			: array();
		?>
		<h2><?php esc_html_e( 'Widget display settings', 'r2c-ai-concierge' ); ?></h2>
		<form id="r2c-settings-form">
			<table class="form-table">
				<tr>
					<th><label for="r2c-position"><?php esc_html_e( 'Position', 'r2c-ai-concierge' ); ?></label></th>
					<td>
						<select id="r2c-position" name="position">
							<option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'r2c-ai-concierge' ); ?></option>
							<option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'r2c-ai-concierge' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="r2c-offset-x"><?php esc_html_e( 'Horizontal offset (px)', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="number" id="r2c-offset-x" name="offset_x" min="0" max="320" value="<?php echo esc_attr( $offset_x ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="r2c-offset-y"><?php esc_html_e( 'Vertical offset (px)', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="number" id="r2c-offset-y" name="offset_y" min="0" max="320" value="<?php echo esc_attr( $offset_y ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="r2c-primary-color"><?php esc_html_e( 'Brand color', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="text" id="r2c-primary-color" name="primary_color" class="r2c-color-field" value="<?php echo esc_attr( $primary_color ); ?>" placeholder="#3B82F6" /></td>
				</tr>
			</table>

			<?php self::render_excluded_pages_section( $excluded ); ?>

			<p>
				<button type="submit" class="button button-primary" id="r2c-settings-save"><?php esc_html_e( 'Save', 'r2c-ai-concierge' ); ?></button>
				<span id="r2c-settings-status" role="status" aria-live="polite"></span>
			</p>
		</form>
		<p class="description"><?php esc_html_e( 'Changes may take up to 5 minutes to take effect.', 'r2c-ai-concierge' ); ?></p>
		<p class="description"><?php esc_html_e( 'A display position you changed in the R2C dashboard is applied to your site when you open this screen.', 'r2c-ai-concierge' ); ?></p>
		<?php
	}

	/**
	 * FR-10: excluded_page_patterns の直接編集(テキストエリア)に加え、
	 * WP固有の「投稿タイプ別」「特定の固定ページ」からの追加を出す。
	 * どちらも最終的に同じテキストエリアへパターン文字列を足すだけ
	 * (assets/js/admin-settings.js の appendPattern)— 保存時の送信経路は
	 * 1本のまま(FR-21のローカル権威化を避ける設計と同じく、経路を増やさない)。
	 */
	private static function render_excluded_pages_section( $patterns ) {
		$post_type_patterns = self::excludable_post_type_patterns();
		$page_map           = self::page_id_to_path_map();
		?>
		<h2><?php esc_html_e( 'Pages to hide the widget on', 'r2c-ai-concierge' ); ?></h2>
		<p class="description"><?php esc_html_e( 'One pattern per line. Start with / ; add * at the end to match everything under that path (e.g. /cart, /checkout/*).', 'r2c-ai-concierge' ); ?></p>
		<p>
			<textarea id="r2c-excluded-patterns" name="excluded_page_patterns" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $patterns ) ); ?></textarea>
		</p>
		<?php if ( empty( $post_type_patterns ) && empty( $page_map ) ) : ?>
			<p class="description">
				<?php if ( '' === get_option( 'permalink_structure' ) ) : ?>
					<?php
					printf(
						/* translators: %s: link to the Permalinks settings screen */
						esc_html__( 'Adding a specific page or post type here requires a "pretty" permalink structure — this site is currently using the default "Plain" one, under which every page and post type archive resolves to the same URL and can\'t be told apart. You can enable pretty permalinks on the %s screen, or type a pattern directly into the box above.', 'r2c-ai-concierge' ),
						'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Permalinks settings', 'r2c-ai-concierge' ) . '</a>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'There are no pages or post type archives available to add here yet. You can still type a pattern directly into the box above.', 'r2c-ai-concierge' ); ?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p>
				<label for="r2c-excluded-post-type"><?php esc_html_e( 'Add a post type:', 'r2c-ai-concierge' ); ?></label>
				<select id="r2c-excluded-post-type">
					<option value=""><?php esc_html_e( 'Please choose', 'r2c-ai-concierge' ); ?></option>
					<?php foreach ( $post_type_patterns as $pattern => $label ) : ?>
						<option value="<?php echo esc_attr( $pattern ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="r2c-excluded-post-type-add"><?php esc_html_e( 'Add', 'r2c-ai-concierge' ); ?></button>
			</p>
			<p>
				<label for="r2c-excluded-page-picker"><?php esc_html_e( 'Add a specific page:', 'r2c-ai-concierge' ); ?></label>
				<?php
				wp_dropdown_pages(
					array(
						'id'                => 'r2c-excluded-page-picker',
						'show_option_none'  => esc_html__( 'Please choose', 'r2c-ai-concierge' ),
						'option_none_value' => '',
						// Keep this dropdown's options exactly in sync with $page_map
						// (JS looks values up in r2cAiConciergeAdmin.pagePaths, built from that
						// same map) — a page absent from the map has no path R2C's
						// exclusion matching could distinguish from any other page.
						'include'           => implode( ',', array_map( 'absint', array_keys( $page_map ) ) ),
					)
				);
				?>
				<button type="button" class="button" id="r2c-excluded-page-add"><?php esc_html_e( 'Add', 'r2c-ai-concierge' ); ?></button>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * パターン値(例: /blog/*)をキーにした投稿タイプ一覧。値はそのまま
	 * excluded_page_patterns に入る文字列 — 選ばせる時点でR2C側の形式
	 * (/から始まる、200文字以内)に合わせておき、保存時に別変換をしない。
	 *
	 * ★"Plain"パーマリンク構造への防御★ この構造の下では
	 * get_post_type_archive_link() が `?post_type=xxx` のクエリ文字列に
	 * フォールバックし、そこから取り出せるパスは常に「/」になる —
	 * つまりどの投稿タイプを選んでも区別が付かない。そのまま
	 * untrailingslashit('/') . '/*' = '/*' を返すと、選んだ投稿タイプ
	 * ではなくサイト全体を除外するパターンになってしまうため、
	 * パスが「/」しか取れない投稿タイプはこの一覧から除外する。
	 */
	private static function excludable_post_type_patterns() {
		$patterns = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			$archive_link = get_post_type_archive_link( $post_type->name );
			if ( ! $archive_link ) {
				continue;
			}
			$path = wp_parse_url( $archive_link, PHP_URL_PATH );
			if ( ! $path || '/' === $path ) {
				continue;
			}
			$pattern              = untrailingslashit( $path ) . '/*';
			$patterns[ $pattern ] = $post_type->labels->name;
		}
		return $patterns;
	}

	/**
	 * 固定ページID → パーマリンクのパス。JS側(ページ選択の「追加」ボタン)が
	 * IDからパターン文字列を引くための対応表。300件で打ち切る — 大規模サイト
	 * でも管理画面の1リクエストを肥大化させすぎない実用上の上限(WP標準の
	 * wp_dropdown_pages自体には上限が無いため、対応表側で線引きする)。
	 *
	 * ★"Plain"パーマリンク構造への防御★ excludable_post_type_patterns()の
	 * コメントと同じ理由で、get_permalink()が`?page_id=xxx`にフォールバック
	 * する環境ではどのページも同じ「/」にしかならない。「/」しか取れない
	 * ページはこの対応表から外し、選んだ1ページではなくサイト全体を除外
	 * するパターンが追加されるのを防ぐ(render_excluded_pages_sectionが
	 * この対応表を見てページ選択UI自体を出すかどうかを決める)。
	 */
	private static function page_id_to_path_map() {
		if ( null !== self::$page_id_to_path_map_cache ) {
			return self::$page_id_to_path_map_cache;
		}

		$page_ids = get_posts(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'numberposts'   => 300, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- deliberate practical cap for this dropdown, see class doc comment above.
				'fields'        => 'ids',
				'no_found_rows' => true,
				'orderby'       => 'title',
				'order'         => 'ASC',
			)
		);

		$map = array();
		foreach ( $page_ids as $page_id ) {
			$path = wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
			if ( $path && '/' !== $path ) {
				$map[ $page_id ] = $path;
			}
		}
		self::$page_id_to_path_map_cache = $map;
		return $map;
	}

	private static function render_disconnect_button() {
		?>
		<hr />
		<?php if ( null !== self::origin_mismatch() ) : ?>
			<p class="description r2c-origin-mismatch-disconnect"><?php esc_html_e( 'This site\'s address is different from the one it was connected with. If this site is a copy of another site, disconnecting here also stops the chat on the original site, because both use the same key.', 'r2c-ai-concierge' ); ?></p>
		<?php endif; ?>
		<p>
			<button type="button" class="button" id="r2c-disconnect-button"><?php esc_html_e( 'Disconnect', 'r2c-ai-concierge' ); ?></button>
			<span id="r2c-disconnect-status" role="status" aria-live="polite"></span>
		</p>
		<?php
	}
}
