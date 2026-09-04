<?php
/**
 * The single admin screen this plugin has: Settings → R2C.
 *
 * ★No local persistence of position/offset/color/excluded-pages as
 * authority★ On every render, if connected, this fetches the current
 * values from R2C (R2C_Api_Client::get_settings) and displays those —
 * never a locally-remembered value pretending to be current (D9 / FR-23).
 * If that fetch fails, the settings form is not shown at all rather than
 * shown with stale or fabricated defaults (FR-24 / NFR-06).
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_Settings_Page {

	const SLUG = 'r2c-ai-concierge';

	// テナントの運用は R2C App(CopilotUI)側で行う(D10)。このプラグインは
	// 設置までが役割であり、FAQ登録・有人対応・課金はここに作らない(FR-28)。
	const APP_URL = 'https://admin.r2c.biz/copilot-preview';

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
			'r2c-admin-settings',
			R2C_AI_CONCIERGE_URL . 'assets/js/admin-settings.js',
			array(),
			R2C_AI_CONCIERGE_VERSION,
			true
		);
		wp_localize_script(
			'r2c-admin-settings',
			'r2cAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( R2C_Ajax::NONCE_ACTION ),
				// FR-10の「特定の固定ページ」選択肢: wp_dropdown_pages()はページIDしか
				// 渡せないため、パターン変換(パーマリンク→パス)はサーバ側でここにまとめて
				// 済ませ、JS側は選ばれたIDでこのマップを引くだけにする。
				'pagePaths' => self::page_id_to_path_map(),
				'i18n'      => array(
					'connecting'        => __( '接続しています…', 'r2c-ai-concierge' ),
					'confirmDisconnect' => __( '本当に接続を解除しますか？会話データとR2Cテナントは削除されません。', 'r2c-ai-concierge' ),
					'saving'            => __( '保存しています…', 'r2c-ai-concierge' ),
					'saved'             => __( '保存しました。', 'r2c-ai-concierge' ),
				),
			)
		);
		wp_enqueue_style(
			'r2c-admin-settings',
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

		if ( R2C_Options::is_connected() ) {
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
			<p><?php esc_html_e( 'R2C は、サイトを訪れたお客様に自動でチャット対応する AI 接客ウィジェットです。下のボタンから接続すると、テーマの編集なしにウィジェットが表示されます。', 'r2c-ai-concierge' ); ?></p>

			<form id="r2c-connect-form">
				<p>
					<label for="r2c-email"><?php esc_html_e( 'メールアドレス', 'r2c-ai-concierge' ); ?></label><br />
					<input type="email" id="r2c-email" name="email" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" required />
				</p>
				<p>
					<label>
						<input type="checkbox" id="r2c-consent" name="consent" value="1" required />
						<?php
						printf(
							/* translators: 1: terms of service link, 2: privacy policy link */
							esc_html__( '接続すると、サイトURL・サイト名・WordPress/プラグインのバージョン・上記メールアドレスが R2C に送信されます。R2C の%1$sと%2$sに同意します。', 'r2c-ai-concierge' ),
							'<a href="https://r2c.biz/legal/terms.html" target="_blank" rel="noopener noreferrer">' . esc_html__( '利用規約', 'r2c-ai-concierge' ) . '</a>',
							'<a href="https://r2c.biz/legal/privacy.html" target="_blank" rel="noopener noreferrer">' . esc_html__( 'プライバシーポリシー', 'r2c-ai-concierge' ) . '</a>'
						);
						?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary" id="r2c-connect-button"><?php esc_html_e( '接続する', 'r2c-ai-concierge' ); ?></button>
				</p>
				<p id="r2c-connect-status" role="status" aria-live="polite"></p>
			</form>

			<details style="margin-top: 24px;">
				<summary><?php esc_html_e( 'すでに R2C アカウントをお持ちの場合', 'r2c-ai-concierge' ); ?></summary>
				<form id="r2c-manual-form" style="margin-top: 12px;">
					<p>
						<label for="r2c-manual-key"><?php esc_html_e( 'APIキー', 'r2c-ai-concierge' ); ?></label><br />
						<input type="text" id="r2c-manual-key" name="api_key" class="regular-text" autocomplete="off" />
					</p>
					<p>
						<button type="submit" class="button" id="r2c-manual-button"><?php esc_html_e( 'このキーで接続する', 'r2c-ai-concierge' ); ?></button>
					</p>
					<p id="r2c-manual-status" role="status" aria-live="polite"></p>
				</form>
			</details>
		</div>
		<?php
	}

	/* 接続済み */

	private static function render_connected() {
		$result = R2C_Api_Client::get_settings( R2C_Options::get_api_key() );

		echo '<p>' . esc_html__( 'ステータス: 接続済み', 'r2c-ai-concierge' ) . '</p>';

		self::render_next_steps();

		if ( ! $result['ok'] ) {
			// FR-24: 取得できない値で「保存できるように見える」フォームを出さない。
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( '現在 R2C に接続できません。設定を変更するには、しばらくしてから再度このページを開いてください。', 'r2c-ai-concierge' )
			);
			self::render_disconnect_button();
			return;
		}

		self::render_status_summary( $result['body'] );
		self::render_widget_settings_form( $result['body'] );
		self::render_disconnect_button();
	}

	/**
	 * FR-12(接続状態・テナントID・プラン・稼働可否を表示)とFR-27(FAQ未登録警告
	 * は設定画面内に限定しサイト全体のadmin noticeにしない)。どちらもWP-13の
	 * GETをそのまま表示するだけで、ローカルに権威を持たせない(D9)。
	 */
	private static function render_status_summary( $body ) {
		$tenant_id    = isset( $body['tenant_id'] ) ? $body['tenant_id'] : R2C_Options::get_tenant_id();
		$is_active    = ! empty( $body['is_active'] );
		$plan         = isset( $body['plan'] ) ? (string) $body['plan'] : '';
		$status_label = $is_active ? __( '稼働中', 'r2c-ai-concierge' ) : __( '停止中', 'r2c-ai-concierge' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th><?php esc_html_e( '稼働状況', 'r2c-ai-concierge' ); ?></th>
					<td><?php echo esc_html( $status_label ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'テナントID', 'r2c-ai-concierge' ); ?></th>
					<td><code><?php echo esc_html( $tenant_id ); ?></code></td>
				</tr>
				<?php if ( '' !== $plan ) : ?>
				<tr>
					<th><?php esc_html_e( 'プラン', 'r2c-ai-concierge' ); ?></th>
					<td><?php echo esc_html( self::plan_label( $plan ) ); ?></td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		if ( ! $is_active ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'このテナントは現在停止中のため、ウィジェットは表示されません。心当たりがない場合はR2C管理画面でプランや支払い状況をご確認ください。', 'r2c-ai-concierge' )
			);
		}
		if ( empty( $body['has_published_faq'] ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
				esc_html__( 'まだ公開済みのFAQが登録されていません。ウィジェットは表示されますが、質問に回答できません。', 'r2c-ai-concierge' ),
				esc_url( self::APP_URL ),
				esc_html__( 'FAQ を登録する', 'r2c-ai-concierge' )
			);
		}
	}

	private static function plan_label( $plan ) {
		$labels = array(
			'free_ad'    => __( '広告付き無料プラン', 'r2c-ai-concierge' ),
			'starter'    => __( 'スタータープラン', 'r2c-ai-concierge' ),
			'standard'   => __( 'スタンダードプラン', 'r2c-ai-concierge' ),
			'growth'     => __( 'グロースプラン', 'r2c-ai-concierge' ),
			'enterprise' => __( 'エンタープライズプラン', 'r2c-ai-concierge' ),
		);
		return isset( $labels[ $plan ] ) ? $labels[ $plan ] : $plan;
	}

	private static function render_next_steps() {
		printf(
			'<div class="notice notice-info r2c-next-steps"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px;"><li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li><li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li></ul></div>',
			esc_html__( '次にやること', 'r2c-ai-concierge' ),
			esc_url( self::APP_URL ),
			esc_html__( 'FAQ を登録する', 'r2c-ai-concierge' ),
			esc_url( self::APP_URL ),
			esc_html__( '会話を見る', 'r2c-ai-concierge' )
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
		<h2><?php esc_html_e( 'ウィジェットの表示設定', 'r2c-ai-concierge' ); ?></h2>
		<form id="r2c-settings-form">
			<table class="form-table">
				<tr>
					<th><label for="r2c-position"><?php esc_html_e( '表示位置', 'r2c-ai-concierge' ); ?></label></th>
					<td>
						<select id="r2c-position" name="position">
							<option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( '右下', 'r2c-ai-concierge' ); ?></option>
							<option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( '左下', 'r2c-ai-concierge' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="r2c-offset-x"><?php esc_html_e( '横方向の余白(px)', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="number" id="r2c-offset-x" name="offset_x" min="0" max="320" value="<?php echo esc_attr( $offset_x ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="r2c-offset-y"><?php esc_html_e( '縦方向の余白(px)', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="number" id="r2c-offset-y" name="offset_y" min="0" max="320" value="<?php echo esc_attr( $offset_y ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="r2c-primary-color"><?php esc_html_e( 'ブランドカラー', 'r2c-ai-concierge' ); ?></label></th>
					<td><input type="text" id="r2c-primary-color" name="primary_color" class="r2c-color-field" value="<?php echo esc_attr( $primary_color ); ?>" placeholder="#3B82F6" /></td>
				</tr>
			</table>

			<?php self::render_excluded_pages_section( $excluded ); ?>

			<p>
				<button type="submit" class="button button-primary" id="r2c-settings-save"><?php esc_html_e( '保存する', 'r2c-ai-concierge' ); ?></button>
				<span id="r2c-settings-status" role="status" aria-live="polite"></span>
			</p>
		</form>
		<p class="description"><?php esc_html_e( '設定の反映には最大5分かかることがあります。', 'r2c-ai-concierge' ); ?></p>
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
		?>
		<h2><?php esc_html_e( '非表示にするページ', 'r2c-ai-concierge' ); ?></h2>
		<p class="description"><?php esc_html_e( '1行に1パターンで指定します。/ から始まり、末尾に * を付けると以下を含めます(例: /cart, /checkout/*)。', 'r2c-ai-concierge' ); ?></p>
		<p>
			<textarea id="r2c-excluded-patterns" name="excluded_page_patterns" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $patterns ) ); ?></textarea>
		</p>
		<p>
			<label for="r2c-excluded-post-type"><?php esc_html_e( '投稿タイプを追加:', 'r2c-ai-concierge' ); ?></label>
			<select id="r2c-excluded-post-type">
				<option value=""><?php esc_html_e( '選択してください', 'r2c-ai-concierge' ); ?></option>
				<?php foreach ( self::excludable_post_type_patterns() as $pattern => $label ) : ?>
					<option value="<?php echo esc_attr( $pattern ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="r2c-excluded-post-type-add"><?php esc_html_e( '追加', 'r2c-ai-concierge' ); ?></button>
		</p>
		<p>
			<label for="r2c-excluded-page-picker"><?php esc_html_e( '特定の固定ページを追加:', 'r2c-ai-concierge' ); ?></label>
			<?php
			wp_dropdown_pages(
				array(
					'id'                => 'r2c-excluded-page-picker',
					'show_option_none'  => esc_html__( '選択してください', 'r2c-ai-concierge' ),
					'option_none_value' => '',
				)
			);
			?>
			<button type="button" class="button" id="r2c-excluded-page-add"><?php esc_html_e( '追加', 'r2c-ai-concierge' ); ?></button>
		</p>
		<?php
	}

	/**
	 * パターン値(例: /blog/*)をキーにした投稿タイプ一覧。値はそのまま
	 * excluded_page_patterns に入る文字列 — 選ばせる時点でR2C側の形式
	 * (/から始まる、200文字以内)に合わせておき、保存時に別変換をしない。
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
			if ( ! $path ) {
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
	 */
	private static function page_id_to_path_map() {
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
			if ( $path ) {
				$map[ $page_id ] = $path;
			}
		}
		return $map;
	}

	private static function render_disconnect_button() {
		?>
		<hr />
		<p>
			<button type="button" class="button" id="r2c-disconnect-button"><?php esc_html_e( '接続を解除する', 'r2c-ai-concierge' ); ?></button>
			<span id="r2c-disconnect-status" role="status" aria-live="polite"></span>
		</p>
		<?php
	}
}
