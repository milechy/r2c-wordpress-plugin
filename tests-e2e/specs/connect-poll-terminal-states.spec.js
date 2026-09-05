// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, gotoSettings, resetMock } = require( '../helpers/wp-admin' );

// R2C_Ajax::handle_poll()'s 'expired' and 'failed' terminal states — the
// mock (r2c-mock-api.php) has supported forced_poll_status since it was
// written, but no spec ever exercised either value; only the "immediately
// provisioned" happy path (connect.spec.js) and the manual-key fallback
// were covered. Both matter for the same reason: the connect button must
// re-enable and show a clear reason rather than getting stuck disabled
// forever (admin-settings.js's TERMINAL_STATUSES list is what unsticks it).
test.describe( 'connect flow: poll terminal states other than success', () => {
	test( 'a forced "expired" poll response shows the expiry message and re-enables the Connect button', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { forced_poll_status: 'expired' } );
		await loginAsAdmin( page, baseURL );
		await gotoSettings( page, baseURL );

		await page.fill( '#r2c-email', 'owner@example.com' );
		await page.check( '#r2c-consent' );
		await page.click( '#r2c-connect-button' );

		await expect( page.locator( '#r2c-connect-status' ) ).toContainText(
			'Site verification expired before it could complete',
			{ timeout: 15000 }
		);
		await expect( page.locator( '#r2c-connect-button' ) ).toBeEnabled();
		// Must not have connected.
		await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
	} );

	test( 'a forced "failed" poll response shows the failure message and re-enables the Connect button', async ( { page, baseURL } ) => {
		await resetMock( baseURL, { forced_poll_status: 'failed' } );
		await loginAsAdmin( page, baseURL );
		await gotoSettings( page, baseURL );

		await page.fill( '#r2c-email', 'owner@example.com' );
		await page.check( '#r2c-consent' );
		await page.click( '#r2c-connect-button' );

		await expect( page.locator( '#r2c-connect-status' ) ).toContainText(
			'Site verification failed. Please try again.',
			{ timeout: 15000 }
		);
		await expect( page.locator( '#r2c-connect-button' ) ).toBeEnabled();
		await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
	} );
} );
