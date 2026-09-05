// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// E-1 / NFR-06: the front-end widget tag comes entirely from locally stored
// settings (R2C_Options::get_cached_theme / get_tenant_id) — R2C_Widget
// never calls the API at request time, so an outage on R2C's side must not
// take the visitor-facing widget down with it.
test( 'front-end widget keeps rendering even while R2C is unreachable', async ( { page, baseURL } ) => {
	await resetMock( baseURL, {
		seed_api_key: 'mock_resilience_key',
		settings: { position: 'bottom-left', offset_x: 88 },
	} );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_resilience_key' );

	await page.goto( baseURL );
	const scriptTag = page.locator( 'script[data-tenant]' );
	const before = await scriptTag.evaluate( ( el ) => el.outerHTML );
	expect( before ).toContain( 'data-position="bottom-left"' );

	await configureMock( baseURL, { force_settings_down: true } );

	await page.goto( baseURL );
	const after = await page.locator( 'script[data-tenant]' ).evaluate( ( el ) => el.outerHTML );
	expect( after ).toBe( before );
} );
