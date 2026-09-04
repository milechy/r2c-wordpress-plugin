// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoSettings, resetMock } = require( '../helpers/wp-admin' );

// B-1: theme-file-free connect flow, and B-9: "next steps" + FAQ/plan/status
// appear once connected.
test( 'consent + connect flow reaches Connected state and shows next steps', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { poll_attempts_until_provisioned: 0 } );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.fill( '#r2c-email', 'owner@example.com' );
	await page.check( '#r2c-consent' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-connect-button' ) ] );

	await page.waitForSelector( '#r2c-disconnect-button' );
	await expect( page.locator( 'body' ) ).toContainText( 'Status: Connected' );
	await expect( page.locator( 'body' ) ).toContainText( 'Tenant ID' );
	await expect( page.locator( 'body' ) ).toContainText( 't_e2e' );
	await expect( page.locator( '.r2c-next-steps' ) ).toContainText( 'Next steps' );
	// has_published_faq defaults to false in the mock's default state (FR-27).
	await expect( page.locator( 'body' ) ).toContainText( 'No published FAQs have been registered yet' );
} );
