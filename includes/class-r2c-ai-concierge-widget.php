<?php
/**
 * The front-end script tag. This is the entire runtime footprint of the
 * plugin on a visitor's page (docs/WORDPRESS_PLUGIN_REQUIREMENTS.md NFR-08:
 * one <script async>, no additional CSS/JS).
 *
 * ★No page-exclusion logic lives here★ `excluded_page_patterns` is applied
 * inside the widget.js R2C's server generates per-tenant (it's baked into
 * that file's content, not passed as a script-tag attribute) — see
 * `generateWidgetJs()` / `GET /widget/:tenantId.js` in the parent repo.
 * This plugin always emits the same tag on every front-end page; R2C's own
 * script decides at runtime whether to actually render the chat bubble on
 * the current URL. Re-implementing that matching logic here would be the
 * "第2のウィジェット実装" CLAUDE.md (parent repo) explicitly forbids.
 *
 * ★Why wp_enqueue_script() instead of a bare printf()★ This is R2C's own
 * remote widget.js (a per-tenant, dynamically-generated URL from
 * api.r2c.biz), not a local plugin asset — and it needs a `data-tenant`
 * attribute plus `async`, neither of which wp_enqueue_script() alone can
 * express. Registering it through wp_enqueue_script() (so it participates
 * in WP's script dependency system like any other script) and then
 * rebuilding its tag via the `script_loader_tag` filter — WordPress's own
 * documented mechanism for attaching custom attributes — gets both: WP
 * standard registration, and the exact tag this plugin has always emitted.
 *
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_AI_Concierge_Widget {

	const HANDLE = 'r2c-ai-concierge-widget';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 10, 2 );
	}

	public static function enqueue() {
		if ( is_admin() || ! R2C_AI_Concierge_Options::is_connected() ) {
			return;
		}

		$tenant_id = R2C_AI_Concierge_Options::get_tenant_id();
		if ( empty( $tenant_id ) ) {
			return;
		}

		// No version query arg (`null`) — this is a remote, per-tenant URL,
		// not a static local asset for WP to cache-bust.
		wp_enqueue_script( self::HANDLE, self::widget_src( $tenant_id ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- deliberate: a remote, server-rendered-per-tenant URL has nothing for a local `?ver=` to cache-bust.
	}

	/**
	 * `script_loader_tag` fires for every enqueued script on the page;
	 * only rewrite the one handle this class registers.
	 */
	public static function filter_script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return self::render_tag();
	}

	/**
	 * Builds the actual `<script>` tag. Split out from filter_script_tag()
	 * (rather than inlined there) so it re-derives connection state from
	 * scratch instead of trusting that enqueue() ran first in the same
	 * request — and so tests can exercise the attribute-building logic by
	 * calling this directly, without needing WP's real hook/filter
	 * dispatch (Brain Monkey does not execute `apply_filters()`).
	 */
	public static function render_tag() {
		if ( is_admin() || ! R2C_AI_Concierge_Options::is_connected() ) {
			return '';
		}

		$tenant_id = R2C_AI_Concierge_Options::get_tenant_id();
		if ( empty( $tenant_id ) ) {
			return '';
		}

		$attrs = self::placement_attributes();

		return sprintf(
			'<script src="%s" data-tenant="%s"%s async></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- false positive: this string is the `script_loader_tag` filter's return value for a handle already registered via wp_enqueue_script() in enqueue() above, not a bare unenqueued script tag. The sniff's text-pattern check for `<script` cannot see that context.
			esc_url( self::widget_src( $tenant_id ) ),
			esc_attr( $tenant_id ),
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped attribute-by-attribute in placement_attributes().
		);
	}

	private static function widget_src( $tenant_id ) {
		return R2C_AI_CONCIERGE_API_BASE . '/widget/' . rawurlencode( $tenant_id ) . '.js';
	}

	/**
	 * Reads the read-through cache (R2C_AI_Concierge_Options::get_cached_theme —
	 * refreshed whenever the settings screen talks to R2C, see
	 * R2C_AI_Concierge_Options' class doc for why this one field is cached locally
	 * despite D9). Defaults are R2C's own defaults (bottom-right, 24px) —
	 * matching those exactly means "not yet customized" emits no
	 * attributes at all, same as R2C's own get_embed_code().
	 */
	private static function placement_attributes() {
		$theme = R2C_AI_Concierge_Options::get_cached_theme();
		$attrs = '';

		$position = isset( $theme['position'] ) ? $theme['position'] : 'bottom-right';
		if ( in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) && 'bottom-right' !== $position ) {
			$attrs .= ' data-position="' . esc_attr( $position ) . '"';
		}

		foreach (
			array(
				'offset_x' => 'data-offset-x',
				'offset_y' => 'data-offset-y',
			) as $key => $attr_name
		) {
			if ( ! isset( $theme[ $key ] ) || '' === $theme[ $key ] ) {
				continue;
			}
			$value = (int) $theme[ $key ];
			if ( $value < 0 || $value > 320 || 24 === $value ) {
				continue;
			}
			$attrs .= ' ' . $attr_name . '="' . esc_attr( $value ) . '"';
		}

		if ( ! empty( $theme['primary_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $theme['primary_color'] ) ) {
			$attrs .= ' data-accent-color="' . esc_attr( $theme['primary_color'] ) . '"';
		}

		return $attrs;
	}
}
