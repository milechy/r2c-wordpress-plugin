// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-UX-18: the settings screen's robustness against the ways a shop owner
// (or time) breaks the setup. One block per item (a)–(e) of the task.

// (a) The widget reads position/colour only from the script tag, so a value
// changed on R2C's side must be copied into the site when the screen is opened.
test.describe( '(a) placement reaches the site without pressing Save', () => {
	test( 'opening the settings screen applies a position changed on R2C to the front-end tag', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_sync_theme_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_sync_theme_key' );

		// Changed on R2C's side only (CopilotUI); the site has not been told.
		await configureMock( baseURL, { settings: { position: 'bottom-left' } } );
		await page.goto( baseURL );
		await expect( page.locator( 'script[data-tenant]' ) ).not.toHaveAttribute( 'data-position', /.+/ );

		await gotoSettings( page, baseURL );
		await expect( page.locator( '#r2c-position' ) ).toHaveValue( 'bottom-left' );
		await expect( page.locator( 'body' ) ).toContainText( 'is applied to your site when you open this screen' );

		await page.goto( baseURL );
		await expect( page.locator( 'script[data-tenant]' ) ).toHaveAttribute( 'data-position', 'bottom-left' );
	} );

	test( 'the automatic connect flow also picks up the position already stored on R2C', async ( { page, baseURL } ) => {
		await resetMock( baseURL, {
			poll_attempts_until_provisioned: 0,
			settings: { position: 'bottom-left' },
		} );
		await loginAsAdmin( page, baseURL );
		await gotoSettings( page, baseURL );
		await page.fill( '#r2c-email', 'owner@example.com' );
		await page.check( '#r2c-consent' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-connect-button' ) ] );
		await page.waitForSelector( '#r2c-disconnect-button' );

		// Read the front end without ever having opened a settings screen that
		// fetched the values: the cache must already be filled by the connect itself.
		await page.goto( baseURL );
		await expect( page.locator( 'script[data-tenant]' ) ).toHaveAttribute( 'data-position', 'bottom-left' );
	} );
} );

// (b) Saving sends only what the owner changed, so a value changed on R2C
// after the screen was opened is not overwritten by the stale one.
test( '(b) saving does not overwrite excluded pages changed on R2C after the screen was opened', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_no_overwrite_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_no_overwrite_key' );

	// The screen is open with an empty exclusion list; meanwhile R2C changes it.
	await configureMock( baseURL, { settings: { excluded_page_patterns: [ '/cart' ] } } );

	await page.selectOption( '#r2c-position', 'bottom-left' );
	await page.click( '#r2c-settings-save' );
	await expect( page.locator( '#r2c-settings-status' ) ).toContainText( 'Saved' );

	await gotoSettings( page, baseURL );
	await expect( page.locator( '#r2c-position' ) ).toHaveValue( 'bottom-left' );
	await expect( page.locator( '#r2c-excluded-patterns' ) ).toHaveValue( '/cart' );
} );

test( '(b) pressing Save with nothing changed sends nothing', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_nothing_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_nothing_key' );

	let saveRequests = 0;
	page.on( 'request', ( req ) => {
		if ( req.url().includes( 'admin-ajax.php' ) && ( req.postData() || '' ).includes( 'r2c_ai_concierge_save_settings' ) ) {
			saveRequests++;
		}
	} );
	await page.click( '#r2c-settings-save' );
	await expect( page.locator( '#r2c-settings-status' ) ).toContainText( 'There is nothing to change.' );
	expect( saveRequests ).toBe( 0 );
} );

// (c) The challenge WP keeps for site verification disappears after 15 minutes
// while R2C keeps answering "pending" for up to 24 hours.
test.describe( '(c) connect polling does not hang', () => {
	test( 'a vanished challenge ends the wait and asks the owner to press Connect again', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { poll_attempts_until_provisioned: 50, clear_challenge_on_poll: true } );
		await loginAsAdmin( page, baseURL );
		await gotoSettings( page, baseURL );
		await page.fill( '#r2c-email', 'owner@example.com' );
		await page.check( '#r2c-consent' );
		await page.click( '#r2c-connect-button' );

		await expect( page.locator( '#r2c-connect-status' ) ).toContainText( 'The time to confirm your site has run out', { timeout: 15000 } );
		await expect( page.locator( '#r2c-connect-button' ) ).toBeEnabled();
		// Not the misleading Basic-auth advice.
		await expect( page.locator( '#r2c-connect-status' ) ).not.toContainText( 'Basic Auth' );
	} );

	test( 'a 500 from R2C is described as R2C having a problem, not as the site being unreachable', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { forced_poll_status: 'server_error' } );
		await loginAsAdmin( page, baseURL );
		await gotoSettings( page, baseURL );
		await page.fill( '#r2c-email', 'owner@example.com' );
		await page.check( '#r2c-consent' );
		await page.click( '#r2c-connect-button' );

		await expect( page.locator( '#r2c-connect-status' ) ).toContainText( 'R2C is having a temporary problem', { timeout: 15000 } );
		await expect( page.locator( '#r2c-connect-status' ) ).not.toContainText( 'Unable to reach R2C' );
	} );
} );

// (d) Domain change / site copy: the saved address no longer matches home_url.
test.describe( '(d) address mismatch', () => {
	test( 'warns, with both addresses, when the current address differs from the one connected', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { seed_api_key: 'mock_origin_key' } );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_origin_key' );
		await expect( page.locator( '.r2c-origin-mismatch' ) ).toHaveCount( 0 );
		await expect( page.locator( '.r2c-origin-mismatch-disconnect' ) ).toHaveCount( 0 );

		await configureMock( baseURL, { site_origin: 'https://old-address.example' } );
		await gotoSettings( page, baseURL );

		const notice = page.locator( '.r2c-origin-mismatch' );
		await expect( notice ).toContainText( 'https://old-address.example' );
		await expect( notice ).toContainText( new URL( baseURL ).origin );
		await expect( notice ).toContainText( 'also stop the original site' );
		await expect( page.locator( '.r2c-origin-mismatch-disconnect' ) ).toContainText( 'stops the chat on the original site' );
	} );
} );

// (e) One source for the plan, and "could not be retrieved" instead of a made-up value.
test.describe( '(e) plan display', () => {
	test( 'shows the plan from the status endpoint, once', async ( { page, baseURL } ) => {
		await resetMock( baseURL, {
			seed_api_key: 'mock_plan_key',
			// The settings endpoint says "starter"; the status endpoint (the single source) says "growth".
			settings: { plan: 'starter' },
			status: { plan: 'growth' },
		} );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_plan_key' );

		await expect( page.locator( 'body' ) ).toContainText( 'Growth plan' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Starter plan' );
		expect( ( await page.locator( 'body' ).innerText() ).split( 'Growth plan' ).length - 1 ).toBe( 1 );
	} );

	test( 'a plan the server could not determine is shown as not retrieved, never as a plan', async ( { page, baseURL } ) => {
		await resetMock( baseURL, {
			seed_api_key: 'mock_plan_null_key',
			status: { plan: null, faq_published_count: null },
		} );
		await loginAsAdmin( page, baseURL );
		await connectViaManualKey( page, baseURL, 'mock_plan_null_key' );

		const planRow = page.locator( 'tr', { has: page.locator( 'th', { hasText: /^Plan$/ } ) } );
		await expect( planRow ).toContainText( 'Could not be retrieved' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Free plan' );
		const faqRow = page.locator( 'tr', { has: page.locator( 'th', { hasText: /^FAQs$/ } ) } );
		await expect( faqRow ).toContainText( 'Could not be retrieved' );
		await expect( faqRow ).not.toContainText( '0 registered' );
	} );
} );
