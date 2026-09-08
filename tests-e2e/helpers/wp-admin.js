// @ts-check
const { request } = require( '@playwright/test' );

// wp-env's fixed default credentials for its dev-environment admin user —
// not a secret, this WordPress instance only ever exists inside a
// throwaway Docker container for the duration of a test run.
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

const SETTINGS_URL = '/wp-admin/options-general.php?page=r2c-ai-concierge';

/**
 * Idempotent: WordPress auto-redirects an already-authenticated session
 * straight from wp-login.php to wp-admin without showing the login form at
 * all, so a second call in the same browser context (e.g.
 * setPermalinkStructure() calling this from both a test.beforeEach() and
 * that same test's later loginAsAdmin()) must not assume the form fields
 * are present.
 */
async function loginAsAdmin( page, baseURL ) {
	await page.goto( `${ baseURL }/wp-login.php` );
	if ( /\/wp-admin\//.test( page.url() ) ) {
		return;
	}
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function gotoSettings( page, baseURL ) {
	await page.goto( `${ baseURL }${ SETTINGS_URL }` );
}

// このwp-envインストールはPermalink構造が既定のPlainのままで、
// /wp-json/... のようなpretty形式はApacheのrewriteが効かず404になる
// (wp-env run wordpressで実測済み)。プラグイン本体はrest_url()経由で
// 正しく?rest_route=形式を使うが、テストヘルパー側はそれをハードコード
// していなかったため、resetMock/configureMockが常に404で無言のまま
// 失敗し続けていた実際の不具合(WP-11 E2E調査で判明)。
function mockRoute( baseURL, path ) {
	return `${ baseURL }/?rest_route=/r2c-mock${ path }`;
}

/**
 * Resets tests-e2e/mu-plugins/r2c-mock-api.php's stored state, optionally
 * applying `configure` in the same call (see that file's handle_test_configure
 * doc comment for the supported keys). Call this at the start of every test
 * — state persists across tests otherwise (it lives in wp_options).
 */
async function resetMock( baseURL, configure ) {
	const ctx = await request.newContext();
	try {
		const resetRes = await ctx.post( mockRoute( baseURL, '/__test__/reset' ) );
		if ( ! resetRes.ok() ) {
			throw new Error( `resetMock: /__test__/reset returned ${ resetRes.status() }` );
		}
		if ( configure && Object.keys( configure ).length > 0 ) {
			const configureRes = await ctx.post( mockRoute( baseURL, '/__test__/configure' ), {
				data: configure,
			} );
			if ( ! configureRes.ok() ) {
				throw new Error( `resetMock: /__test__/configure returned ${ configureRes.status() }` );
			}
		}
	} finally {
		await ctx.dispose();
	}
}

async function configureMock( baseURL, configure ) {
	const ctx = await request.newContext();
	try {
		const res = await ctx.post( mockRoute( baseURL, '/__test__/configure' ), {
			data: configure,
		} );
		if ( ! res.ok() ) {
			throw new Error( `configureMock: /__test__/configure returned ${ res.status() }` );
		}
	} finally {
		await ctx.dispose();
	}
}

/**
 * Connects via the "Enter API key manually" fallback (FR-05) — used as a
 * fast, deterministic way for other specs to reach an already-connected
 * state without going through the full consent + polling flow.
 */
async function connectViaManualKey( page, baseURL, apiKey ) {
	await gotoSettings( page, baseURL );
	await page.click( 'summary' );
	await page.fill( '#r2c-manual-key', apiKey );
	await Promise.all( [
		page.waitForNavigation(),
		page.click( '#r2c-manual-button' ),
	] );
	// render_connected() always shows this once R2C_AI_Concierge_Options::is_connected() is true.
	await page.waitForSelector( '#r2c-disconnect-button' );
}

// A setPermalinkStructure() helper (switching this wp-env install's live
// permalink structure to "pretty" via wp-admin, to E2E-test the positive
// path of the class-r2c-ai-concierge-settings-page.php fix) was tried here and removed.
// Doing so reproducibly triggers a WordPress-core-level fatal in this
// container on every request afterwards ("Call to a member function
// using_index_permalinks() on null" in wp-includes/rest-api.php) — the
// same class of rewrite-rule fragility this container already has (see
// tests-e2e/README.md's note on why .wp-env.json pins no phpVersion), not
// a bug in this plugin. The positive path is covered instead by
// tests/test-settings-page-page-id-to-path-map.php and
// tests/test-settings-page-helpers.php, which don't depend on this
// container's rewrite support.

module.exports = {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
	SETTINGS_URL,
};
