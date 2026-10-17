// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-UX-07: a key R2C no longer accepts (401) must not be presented as
// "Connected" with an outage message — that sends the owner to wait for
// something that will never recover. It must say the key is no longer
// valid and offer the paste box right there. A temporary outage (503,
// api-down.spec.js) keeps the "try again in a moment" wording.
test( 'a revoked key shows "no longer valid" instead of "Connected", and can be replaced from the same screen', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_revoked_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_revoked_key' );

	await configureMock( baseURL, { revoke_api_key: 'mock_revoked_key', seed_api_key: 'mock_replacement_key' } );
	await gotoSettings( page, baseURL );

	await expect( page.locator( 'body' ) ).toContainText( 'Status: Connection is no longer valid' );
	await expect( page.locator( 'body' ) ).not.toContainText( 'Status: Connected' );
	await expect( page.locator( '.notice-error' ) ).toContainText( 'no longer valid' );
	await expect( page.locator( 'body' ) ).not.toContainText( 'reopen this page in a moment' );
	await expect( page.locator( '#r2c-settings-form' ) ).toHaveCount( 0 );

	// Reconnect with a whole pasted script tag, from this same screen.
	await page.fill(
		'#r2c-manual-key',
		'<script src="https://api.r2c.biz/widget/t.js" data-api-key="mock_replacement_key" async></script>'
	);
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-manual-button' ) ] );

	await page.waitForSelector( '#r2c-settings-form' );
	await expect( page.locator( 'body' ) ).toContainText( 'Status: Connected' );
} );
