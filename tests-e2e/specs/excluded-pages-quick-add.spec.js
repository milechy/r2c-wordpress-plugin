// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// FR-10's "Add a specific page" quick-add button (assets/js/admin-settings.js
// initExcludedPagesHelpers/appendExcludedPattern) has no coverage anywhere
// else — every other spec only edits #r2c-excluded-patterns directly.
test.describe( 'excluded pages: "Add a specific page" quick-add', () => {
	test( 'adding the default Sample Page appends a pattern to the textarea', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_key' );

		const textarea = page.locator( '#r2c-excluded-patterns' );
		await expect( textarea ).toHaveValue( '' );

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );

		const value = await textarea.inputValue();
		expect( value.trim().length, 'clicking Add must append something to the textarea' ).toBeGreaterThan( 0 );

		// ★Risk finding (see PR description / test-settings-page-page-id-to-path-map.php)★
		// This WordPress install uses the default "Plain" permalink structure
		// (confirmed during WP-11's E2E debugging). Under that structure,
		// get_permalink() for every page falls back to `?page_id={id}`, and
		// page_id_to_path_map() (class-r2c-settings-page.php) turns that into
		// the path "/" for every single page — not a pattern that identifies
		// the selected page. If this assertion ever starts failing because
		// the value is no longer "/", that means the underlying bug was
		// fixed (page_id_to_path_map should be made to fall back to the
		// page's slug) — update this test to match the fixed behaviour
		// rather than re-asserting "/".
		expect( value.trim() ).toBe( '/' );
	} );

	test( 'adding the same page twice does not create a duplicate line', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_dedupe_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_dedupe_key' );

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );
		const afterFirstAdd = await page.locator( '#r2c-excluded-patterns' ).inputValue();

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );
		const afterSecondAdd = await page.locator( '#r2c-excluded-patterns' ).inputValue();

		expect( afterSecondAdd ).toBe( afterFirstAdd );
		expect( afterSecondAdd.split( '\n' ).filter( ( l ) => l.trim() !== '' ) ).toHaveLength( 1 );
	} );

	test( 'a manually-typed duplicate is not re-added by the quick-add button', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_manual_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_manual_key' );

		// Pre-seed the textarea with a pattern that (under this install's
		// Plain permalinks) is exactly what the quick-add button would also
		// produce — plus an unrelated, distinct manual entry.
		await page.fill( '#r2c-excluded-patterns', '/cart\n/' );

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );

		const value = await page.locator( '#r2c-excluded-patterns' ).inputValue();
		const lines = value.split( '\n' ).filter( ( l ) => l.trim() !== '' );
		expect( lines ).toEqual( [ '/cart', '/' ] );
	} );
} );
