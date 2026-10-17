// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	mockHits,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-UX-05: what a shop owner is told on the connect screen. Each test is a
// way a real owner gets this wrong without the sentence being there.

test( 'disconnected screen shows both entrances and talks to R2C zero times', async ( { page, baseURL } ) => {
	await resetMock( baseURL );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await expect( page.locator( '#r2c-entry-new' ) ).toBeVisible();
	await expect( page.locator( '#r2c-entry-existing' ) ).toBeVisible();

	// 既存契約者の入口は閉じたままでも見出しで気づける。開くと案内と入力欄が出る。
	await expect( page.locator( '#r2c-manual-key' ) ).toBeHidden();
	await page.click( '#r2c-entry-existing summary' );
	await expect( page.locator( '#r2c-entry-existing' ) ).toContainText( 'Issue a connection key for WordPress' );
	await expect( page.locator( '#r2c-manual-key' ) ).toBeVisible();

	// GL#7: 画面を開いて眺めただけでは、R2C へ1回も通信しない。
	expect( await mockHits( baseURL ) ).toBe( 0 );
} );

test( 'free plan conditions are above the consent checkbox, where the owner reads them first', async ( { page, baseURL } ) => {
	await resetMock( baseURL );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	const terms = page.locator( '.r2c-free-terms' );
	await expect( terms ).toContainText( 'Ads and R2C branding' );
	await expect( terms ).toContainText( '200 conversations per month' );
	await expect( terms ).toContainText( 'shared learning' );
	await expect( page.locator( '#r2c-entry-new a[href="https://r2c.biz/lp/"]' ) ).toBeVisible();

	const termsBox = await terms.boundingBox();
	const consentBox = await page.locator( '#r2c-consent' ).boundingBox();
	expect( termsBox && consentBox && termsBox.y < consentBox.y ).toBeTruthy();

	// 条件を読んでも同意なしでは接続できない(native required)。通信も起きない。
	await page.click( '#r2c-connect-button' );
	await expect( page.locator( '#r2c-disconnect-button' ) ).toHaveCount( 0 );
	expect( await mockHits( baseURL ) ).toBe( 0 );
} );

test( 'after connecting with the free flow, next steps explain the invitation email login', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { poll_attempts_until_provisioned: 0 } );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	await page.fill( '#r2c-email', 'owner@example.com' );
	await page.check( '#r2c-consent' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-connect-button' ) ] );

	const steps = page.locator( '.r2c-next-steps' );
	await expect( steps ).toContainText( 'invitation email' );
	await expect( steps ).toContainText( 'set a password' );
	await expect( steps ).toContainText( 'spam folder' );
} );

// 「接続解除で解約された」と思い込むのが最大の事故。1回目のクリックでは
// 解除されず、解約ではない旨が出る。2回目で初めて解除される。
test( 'disconnect asks first and says it does not cancel the contract', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_guidance_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_guidance_key' );

	await page.click( '#r2c-disconnect-button' );
	await expect( page.locator( '#r2c-disconnect-status' ) ).toContainText( 'does not cancel your contract' );
	await expect( page.locator( '#r2c-disconnect-status' ) ).toContainText( 'I want to cancel' );

	// まだ接続されたまま(フロントのスクリプトも出ている)。
	await page.goto( baseURL );
	await expect( page.locator( 'script[data-tenant]' ) ).toHaveCount( 1 );
} );

// 解除→再インストール後に再接続、の入口。解除後の画面にも同じ案内が戻る。
test( 'after disconnecting, the connect screen shows the same guidance again', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_guidance_key2' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_guidance_key2' );

	await page.click( '#r2c-disconnect-button' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#r2c-disconnect-button' ) ] );

	await page.waitForSelector( '#r2c-connect-form' );
	await expect( page.locator( '#r2c-entry-new' ) ).toBeVisible();
	await expect( page.locator( '#r2c-entry-existing' ) ).toBeVisible();
	await expect( page.locator( '.r2c-free-terms' ) ).toContainText( '200 conversations per month' );
} );
