// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// B-7 / FR-24 / 禁止44: when R2C is unreachable, the settings screen must
// never look like a save succeeded (or even like a save is possible) when
// it wasn't. render_connected() enforces this by not rendering the
// settings form at all when the live GET fails — there is no "Saved."
// message to falsely show because there is nothing to submit.
test( 'settings form does not appear — and nothing claims to save — while R2C is unreachable', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_down_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_down_key' );

	await configureMock( baseURL, { force_settings_down: true } );
	await gotoSettings( page, baseURL );

	await expect( page.locator( '.notice-error' ) ).toContainText( 'Unable to reach R2C' );
	await expect( page.locator( '#r2c-settings-form' ) ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).not.toContainText( 'Saved.' );
	// Disconnecting must still be possible even while R2C is down (FR-07's
	// "delete local credentials regardless of the remote call's outcome").
	await expect( page.locator( '#r2c-disconnect-button' ) ).toBeVisible();
} );
