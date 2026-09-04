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
 * @package R2C_AI_Concierge
 */

defined( 'ABSPATH' ) || exit;

class R2C_Widget {

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( is_admin() || ! R2C_Options::is_connected() ) {
			return;
		}

		$tenant_id = R2C_Options::get_tenant_id();
		if ( empty( $tenant_id ) ) {
			return;
		}

		$attrs = self::placement_attributes();

		// This is R2C's own remote widget.js (a per-tenant, dynamically-generated URL from
		// api.r2c.biz), not a local plugin asset. wp_enqueue_script() has no way to express
		// "async, no local file, runtime-computed tenant-scoped URL" — the same reasoning
		// get_embed_code() in the parent repo already documents for this exact embed.
		printf(
			'<script src="%s" data-tenant="%s"%s async></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			esc_url( R2C_AI_CONCIERGE_API_BASE . '/widget/' . rawurlencode( $tenant_id ) . '.js' ),
			esc_attr( $tenant_id ),
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped attribute-by-attribute in placement_attributes().
		);
	}

	/**
	 * Reads the read-through cache (R2C_Options::get_cached_theme —
	 * refreshed whenever the settings screen talks to R2C, see
	 * R2C_Options' class doc for why this one field is cached locally
	 * despite D9). Defaults are R2C's own defaults (bottom-right, 24px) —
	 * matching those exactly means "not yet customized" emits no
	 * attributes at all, same as R2C's own get_embed_code().
	 */
	private static function placement_attributes() {
		$theme = R2C_Options::get_cached_theme();
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
