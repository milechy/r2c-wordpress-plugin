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
	// render_connected() always shows this once R2C_Options::is_connected() is true.
	await page.waitForSelector( '#r2c-disconnect-button' );
}

/**
 * This wp-env install defaults to WordPress's own "Plain" permalink
 * structure (value=""), under which get_permalink()/
 * get_post_type_archive_link() fall back to `?page_id=`/`?post_type=`
 * query strings — the condition class-r2c-settings-page.php's
 * page_id_to_path_map()/excludable_post_type_patterns() specifically guard
 * against (see excluded-pages-quick-add.spec.js). `value` attributes for
 * WordPress core's built-in permalink presets are stable across versions,
 * unlike the radio inputs' element ids.
 *
 * Logs in itself rather than assuming the caller already did — every
 * Playwright test gets a fresh, unauthenticated browser context, and this
 * is called from test.beforeEach()/afterEach() hooks that run before a
 * test body's own loginAsAdmin() call. Navigating to options-permalink.php
 * while logged out silently lands on wp-login.php instead, where the
 * selection radios don't exist — that (not a selector mismatch) is what
 * actually caused this to hang for a full 30s the first time around.
 */
async function setPermalinkStructure( page, baseURL, structure ) {
	const value = 'pretty' === structure ? '/%postname%/' : '';
	await loginAsAdmin( page, baseURL );
	await page.goto( `${ baseURL }/wp-admin/options-permalink.php` );
	await page.check( `input[name="selection"][value="${ value }"]` );
	await Promise.all( [
		page.waitForNavigation(),
		page.click( '#submit' ),
	] );
}

module.exports = {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
	setPermalinkStructure,
	SETTINGS_URL,
};
