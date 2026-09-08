// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// FR-07 end-to-end: local disconnection must succeed even while R2C itself
// is unreachable — api-down.spec.js proves the settings *form* disappears
// during an outage, and disconnect.spec.js proves disconnecting works while
// R2C is healthy, but nothing exercised disconnecting *during* an outage,
// which is arguably the single most important moment for this guarantee to
// actually hold (an admin trying to make an unwanted/broken widget go away
// right now is exactly when R2C being down is most likely to be relevant).
test( 'disconnecting while R2C is unreachable still removes the connection and the front-end widget', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_disconnect_down_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_disconnect_down_key' );

	await page.goto( baseURL );
	await expect( page.locator( 'script[data-tenant]' ) ).toHaveCount( 1 );

	await configureMock( baseURL, { force_settings_down: true } );
	await gotoSettings( page, baseURL );

	// render_connected() shows the "unable to reach" notice and hides the
	// settings form while down (api-down.spec.js), but the disconnect
	// button must still be present and functional (R2C_AI_Concierge_Ajax::handle_disconnect
	// clears local state unconditionally per FR-07 regardless of the
	// R2C_AI_Concierge_Api_Client::disconnect() outcome).
	await expect( page.locator( '#r2c-disconnect-button' ) ).toBeVisible();

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
