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

define( 'R2C_AI_CONCIERGE_API_BASE', home_url( '/wp-json/r2c-mock' ) );
