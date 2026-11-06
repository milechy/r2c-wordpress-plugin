// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoSettings, resetMock, connectViaManualKey } = require( '../helpers/wp-admin' );

// The management code (separate from the connection key) is what R2C requires to change the hidden
// pages or to revoke the key. Without one, the hidden-pages field is disabled (an editable field
// that always fails is worse than no field), position/color still save, and the code can be added
// on the same screen.
test( 'without a management code the hidden pages are locked, the rest still saves, and adding the code unlocks them', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_manage_token_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_manage_token_key', '' );

	await expect( page.locator( '#r2c-excluded-patterns' ) ).toBeDisabled();
	await expect( page.locator( '.r2c-manage-token-needed' ) ).toBeVisible();

	await page.selectOption( '#r2c-position', 'bottom-left' );
	await page.click( '#r2c-settings-save' );
	await expect( page.locator( '#r2c-settings-status' ) ).toContainText( 'Saved' );

	await page.fill( '#r2c-manage-token', 'r2cm_e2e_added_later' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-manage-token-save' ) ] );

	await expect( page.locator( '#r2c-excluded-patterns' ) ).toBeEnabled();
	// The saved value is never printed back.
	await expect( page.locator( 'body' ) ).not.toContainText( 'r2cm_e2e_added_later' );
} );

test( 'a connection key pasted into the management code field is rejected without connecting', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_wrong_field_key' } );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );
	await page.click( 'summary' );
	await page.fill( '#r2c-manual-key', 'mock_wrong_field_key' );
	await page.fill( '#r2c-manual-manage-token', 'rjc_this_is_the_connection_key' );
	await page.click( '#r2c-manual-button' );

	await expect( page.locator( '#r2c-manual-status' ) ).toContainText( 'management code' );
	await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
} );
