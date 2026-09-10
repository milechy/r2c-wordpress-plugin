// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	configureMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-18/D13(FR-34〜40): the settings screen shows a read-only "This week"
// summary sourced from GET /v1/public/wp/status — separate from the
// settings-sync data tested in settings-sync.spec.js.
test( 'settings screen shows the read-only usage summary from the status endpoint', async ( { page, baseURL } ) => {
	await resetMock( baseURL, {
		seed_api_key: 'mock_status_key',
		status: {
			plan: 'growth',
			avatar_active: true,
			faq_published_count: 5,
			open_escalations_count: 2,
			weekly_summary: { sessions_this_week: 12, learned_this_week: 3 },
		},
	} );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_status_key' );
	await gotoSettings( page, baseURL );

	const body = page.locator( 'body' );
	await expect( body ).toContainText( '12' );
	await expect( body ).toContainText( '3' );
	await expect( body ).toContainText( 'Growth plan' );
	await expect( body ).toContainText( 'Active' );
	await expect( body ).toContainText( '5' );
	await expect( body ).toContainText( '2' );

	// D10/やってはいけないこと: no operational buttons (avatar on/off etc.) in
	// this block — display-only, with a link out to the R2C App instead.
	await expect( page.locator( '#r2c-avatar-toggle' ) ).toHaveCount( 0 );
} );

// FR-40: unreachable status endpoint must show a clear "unavailable" message,
// not a stale/previous value and not an infinite loading state.
test( 'usage summary shows an unavailable message when the status endpoint is down', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_status_down_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_status_down_key' );

	await configureMock( baseURL, { force_settings_down: true } );
	await gotoSettings( page, baseURL );

	await expect( page.locator( 'body' ) ).toContainText( 'Unable to retrieve this information right now.' );
} );
