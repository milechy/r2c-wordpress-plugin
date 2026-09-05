// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// B-3: display position (and offset) changes reach the front-end script tag.
test( 'saved position/offset are reflected in the front-end widget tag', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_save_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_save_key' );

	await page.selectOption( '#r2c-position', 'bottom-left' );
	await page.fill( '#r2c-offset-x', '100' );
	await page.click( '#r2c-settings-save' );
	await expect( page.locator( '#r2c-settings-status' ) ).toContainText( 'Saved' );

	await page.goto( baseURL );
	const scriptTag = page.locator( 'script[data-tenant]' );
	await expect( scriptTag ).toHaveAttribute( 'data-position', 'bottom-left' );
	await expect( scriptTag ).toHaveAttribute( 'data-offset-x', '100' );
	// offset_y was left at the default (24) — placement_attributes() omits
	// attributes matching R2C's own defaults, same convention as
	// get_embed_code() in the parent repo.
	await expect( scriptTag ).not.toHaveAttribute( 'data-offset-y', /.+/ );
} );

// FR-10: the excluded-pages textarea round-trips through R2C (not cached
// locally as authoritative — every save re-fetches on the next page load).
test( 'excluded page patterns persist across a reload', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_excluded_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_excluded_key' );

	await page.fill( '#r2c-excluded-patterns', '/cart\n/checkout/*' );
	await page.click( '#r2c-settings-save' );
	await expect( page.locator( '#r2c-settings-status' ) ).toContainText( 'Saved' );

	await gotoSettings( page, baseURL );
	await expect( page.locator( '#r2c-excluded-patterns' ) ).toHaveValue( '/cart\n/checkout/*' );
} );
