// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoSettings, resetMock } = require( '../helpers/wp-admin' );

// Code review finding: the connect form's fetch() `.catch()` handler used
// to leave the earlier "Connecting…" text in place on a genuine
// network-level failure (as opposed to a server-side error response),
// which looks exactly like the request is still in progress rather than
// having failed. This is distinct from the poll loop's own `.catch()`,
// which deliberately keeps retrying (NFR-06) and is intentionally
// untouched by this fix.
test( 'a network-level failure on the initial connect request shows a clear error, not a stuck "Connecting…"', async ( { page, baseURL } ) => {
	await resetMock( baseURL );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.route( '**/admin-ajax.php', ( route ) => route.abort( 'failed' ) );

	await page.fill( '#r2c-email', 'owner@example.com' );
	await page.check( '#r2c-consent' );
	await page.click( '#r2c-connect-button' );

	await expect( page.locator( '#r2c-connect-status' ) ).not.toHaveText( 'Connecting…' );
	await expect( page.locator( '#r2c-connect-status' ) ).toContainText( 'Unable to reach the server' );
	await expect( page.locator( '#r2c-connect-button' ) ).toBeEnabled();
} );
