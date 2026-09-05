// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	connectViaManualKey,
	setPermalinkStructure,
} = require( '../helpers/wp-admin' );

// FR-10's "Add a specific page" / "Add a post type" quick-add buttons
// (assets/js/admin-settings.js initExcludedPagesHelpers/appendExcludedPattern)
// had no coverage anywhere else — every other spec only edits
// #r2c-excluded-patterns directly.
//
// This install defaults to WordPress's own "Plain" permalink structure.
// Under Plain, get_permalink()/get_post_type_archive_link() fall back to
// `?page_id=`/`?post_type=` query strings, and every single page/post type
// resolves to the exact same "/" path — page_id_to_path_map() and
// excludable_post_type_patterns() (class-r2c-settings-page.php) used to
// turn that into the pattern "/" or "/*" regardless of which page/post
// type was actually selected, which would exclude the entire site rather
// than the one page picked. Both helpers now skip any entry that only
// resolves to "/", and render_excluded_pages_section() replaces the
// pickers with an explanatory note when nothing is left to offer.
test.describe( 'excluded pages: quick-add under Plain permalinks (this install\'s default)', () => {
	test( 'the post-type/page pickers are replaced by an explanatory note instead of a footgun', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_plain_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_plain_key' );

		await expect( page.locator( '#r2c-excluded-post-type' ) ).toHaveCount( 0 );
		await expect( page.locator( '#r2c-excluded-page-picker' ) ).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).toContainText( 'requires a "pretty" permalink structure' );
	} );
} );

test.describe( 'excluded pages: quick-add under a pretty permalink structure', () => {
	test.beforeEach( async ( { page, baseURL } ) => {
		await setPermalinkStructure( page, baseURL, 'pretty' );
	} );

	// However this test ends, this install's default (Plain) must be
	// restored — every other spec file in this suite (and the "Plain"
	// describe block above) assumes it.
	test.afterEach( async ( { page, baseURL } ) => {
		await setPermalinkStructure( page, baseURL, 'plain' );
	} );

	test( 'adding the default Sample Page appends its actual slug-based path', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_pretty_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_pretty_key' );

		await expect( page.locator( '#r2c-excluded-page-picker' ) ).toBeVisible();

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );

		const value = ( await page.locator( '#r2c-excluded-patterns' ).inputValue() ).trim();
		expect( value ).toBe( '/sample-page/' );
	} );

	test( 'adding the same page twice does not create a duplicate line', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_pretty_dedupe_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_pretty_dedupe_key' );

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
		await resetMock( baseURL, { seed_api_key: 'mock_quickadd_pretty_manual_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_quickadd_pretty_manual_key' );

		await page.fill( '#r2c-excluded-patterns', '/cart\n/sample-page/' );

		await page.selectOption( '#r2c-excluded-page-picker', { label: 'Sample Page' } );
		await page.click( '#r2c-excluded-page-add' );

		const value = await page.locator( '#r2c-excluded-patterns' ).inputValue();
		const lines = value.split( '\n' ).filter( ( l ) => l.trim() !== '' );
		expect( lines ).toEqual( [ '/cart', '/sample-page/' ] );
	} );
} );
