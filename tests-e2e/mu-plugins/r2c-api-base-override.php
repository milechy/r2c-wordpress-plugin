<?php
/**
 * E2E専用。R2C_AI_CONCIERGE_API_BASEを実際のapi.r2c.bizではなく、この
 * WP自身に生やしたモックAPI(r2c-mock-api.php)へ向ける。must-use pluginは
 * 通常のプラグインより先に読み込まれるため、r2c-ai-concierge.php本体の
 * `if ( ! defined() )` ガードより確実に先に定義される。
 *
 * .wp-env.json の mappings 経由でのみ読み込まれ、配布用プラグインには
 * 含まれない(.distignore参照)。
 *
 * @package R2C_AI_Concierge_E2E
 */

defined( 'ABSPATH' ) || exit;

/**
 * home_url()(and by extension rest_url()) reports the port wp-env exposes
 * on the *host* (e.g. :8888), but wp_remote_request() below runs inside
 * this same container, where the webserver actually listens on the
 * default port 80 — a literal home_url()-based URL is unreachable from in
 * here (confirmed via CI: every request came back as a transport failure).
 * rest_url() still picks the right REST path form (pretty /wp-json/ vs the
 * ?rest_route= fallback) for however this install's permalinks are
 * configured; only the host:port prefix needs correcting to the
 * container-internal address.
 */
$mock_base = str_replace( home_url(), 'http://localhost', rest_url( 'r2c-mock' ) );

define( 'R2C_AI_CONCIERGE_API_BASE', untrailingslashit( $mock_base ) );
