// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoSettings, resetMock } = require( '../helpers/wp-admin' );

// B-5: the manual API-key fallback (FR-05) completes a connection on its own,
// without going through the consent/sign-up flow at all.
test( 'manual API key entry connects without using the sign-up flow', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_manual_e2e_key' } );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.click( 'summary' );
	await page.fill( '#r2c-manual-key', 'mock_manual_e2e_key' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-manual-button' ) ] );

	await page.waitForSelector( '#r2c-disconnect-button' );
	await expect( page.locator( 'body' ) ).toContainText( 'Status: Connected' );
} );

test( 'an unrecognized manual key is rejected with an inline error', async ( { page, baseURL } ) => {
	await resetMock( baseURL );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.click( 'summary' );
	await page.fill( '#r2c-manual-key', 'mock_not_a_real_key' );
	await page.click( '#r2c-manual-button' );

	await expect( page.locator( '#r2c-manual-status' ) ).toContainText( 'invalid', { ignoreCase: true } );
	// Must not have connected.
	await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
} );

// #r2c-manual-key has no `required` attribute (unlike the connect form's
// email/consent) — a user can submit this form completely blank with no
// browser-side gate at all. The server-side empty-key check is the only
// thing standing between that click and a wasted round trip.
test( 'submitting the manual key form with an empty field is rejected without connecting', async ( { page, baseURL } ) => {
	await resetMock( baseURL );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.click( 'summary' );
	await page.click( '#r2c-manual-button' );

	await expect( page.locator( '#r2c-manual-status' ) ).toContainText( 'Please enter an API key' );
	await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
} );
