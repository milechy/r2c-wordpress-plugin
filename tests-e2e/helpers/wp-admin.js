// @ts-check
const { request } = require( '@playwright/test' );

// wp-env's fixed default credentials for its dev-environment admin user —
// not a secret, this WordPress instance only ever exists inside a
// throwaway Docker container for the duration of a test run.
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

const SETTINGS_URL = '/wp-admin/options-general.php?page=r2c-ai-concierge';

async function loginAsAdmin( page, baseURL ) {
	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function gotoSettings( page, baseURL ) {
	await page.goto( `${ baseURL }${ SETTINGS_URL }` );
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
		await ctx.post( `${ baseURL }/wp-json/r2c-mock/v1/__test__/reset` );
		if ( configure && Object.keys( configure ).length > 0 ) {
			await ctx.post( `${ baseURL }/wp-json/r2c-mock/v1/__test__/configure`, {
				data: configure,
			} );
		}
	} finally {
		await ctx.dispose();
	}
}

async function configureMock( baseURL, configure ) {
	const ctx = await request.newContext();
	try {
		await ctx.post( `${ baseURL }/wp-json/r2c-mock/v1/__test__/configure`, {
			data: configure,
		} );
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

module.exports = {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
	SETTINGS_URL,
};
