// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// B-6 / FR-23: the settings screen must show whatever R2C currently has —
// never a locally-cached value — so a change made elsewhere (CopilotUI, in
// this mock's place) is reflected the next time the WP screen is opened,
// without the admin doing anything else.
test( 'settings screen always reflects the latest value from R2C, never a stale cache', async ( { page, baseURL } ) => {
	await resetMock( baseURL, {
		seed_api_key: 'mock_sync_key',
		settings: { position: 'bottom-left' },
	} );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_sync_key' );

	await expect( page.locator( '#r2c-position' ) ).toHaveValue( 'bottom-left' );

	// Simulate the value changing on R2C's side only (e.g. via CopilotUI) —
	// nothing in this WP install is told about it directly.
	await configureMock( baseURL, { settings: { position: 'bottom-right' } } );

	await gotoSettings( page, baseURL );

	await expect( page.locator( '#r2c-position' ) ).toHaveValue( 'bottom-right' );
} );
