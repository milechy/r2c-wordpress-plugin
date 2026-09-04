// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// B-2: once disconnected, the front-end must stop outputting the widget
// script entirely.
test( 'disconnecting removes the widget script from the front end', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_disconnect_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_disconnect_key' );

	await page.goto( baseURL );
	await expect( page.locator( 'script[data-tenant]' ) ).toHaveCount( 1 );

	await gotoSettings( page, baseURL );
	// Two-step inline confirm (assets/js/admin-settings.js) — no native confirm().
	await page.click( '#r2c-disconnect-button' );
	await Promise.all( [
		page.waitForNavigation(),
		page.click( '#r2c-disconnect-button' ),
	] );

	await page.waitForSelector( '#r2c-connect-form' );
	await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );

	await page.goto( baseURL );
	await expect( page.locator( 'script[data-tenant]' ) ).toHaveCount( 0 );
} );
