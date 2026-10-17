// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	resetMock,
	mockHits,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-UX-09: an owner who never got the invitation email can't log in to R2C
// (no FAQs, no plan change, no cancellation). The connected screen has a
// button that asks R2C to send it again; every outcome says what to do next.

async function connect( page, baseURL, key, configure ) {
	await resetMock( baseURL, { seed_api_key: key, ...( configure || {} ) } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, key );
}

test( 'resend button shows where the address was sent (masked) and cannot be pressed again', async ( { page, baseURL } ) => {
	await connect( page, baseURL, 'mock_resend_key' );

	await page.click( '#r2c-resend-invite-button' );

	const status = page.locator( '#r2c-resend-invite-status' );
	await expect( status ).toContainText( 'ow***@example.com' );
	await expect( status ).toContainText( 'spam folder' );
	await expect( page.locator( '#r2c-resend-invite-button' ) ).toBeDisabled();
} );

// 1時間に1回の制限(429)に当たったら、待つ旨を言う。サーバ文言(日本語固定)を
// そのまま出さず、画面の言語の文言になる。
test( 'a second request within the hour tells the owner to wait, not that it failed', async ( { page, baseURL } ) => {
	await connect( page, baseURL, 'mock_resend_429_key', { resend_invite_result: 'rate_limit' } );

	await page.click( '#r2c-resend-invite-button' );

	const status = page.locator( '#r2c-resend-invite-status' );
	await expect( status ).toContainText( 'wait about an hour' );
	await expect( status ).not.toContainText( 'mock-server-text' );
	await expect( page.locator( '#r2c-resend-invite-button' ) ).toBeEnabled();
} );

test( 'no recipient on record points to the usual login or support', async ( { page, baseURL } ) => {
	await connect( page, baseURL, 'mock_resend_404_key', { resend_invite_result: 'no_recipient' } );

	await page.click( '#r2c-resend-invite-button' );

	const status = page.locator( '#r2c-resend-invite-status' );
	await expect( status ).toContainText( 'connection key' );
	await expect( status ).toContainText( 'contact R2C support' );
} );

test( 'a send failure says to retry later, then contact support', async ( { page, baseURL } ) => {
	await connect( page, baseURL, 'mock_resend_502_key', { resend_invite_result: 'send_failed' } );

	await page.click( '#r2c-resend-invite-button' );

	const status = page.locator( '#r2c-resend-invite-status' );
	await expect( status ).toContainText( 'try again in about an hour' );
	await expect( status ).toContainText( 'contact R2C support' );
	await expect( page.locator( '#r2c-resend-invite-button' ) ).toBeEnabled();
} );

// ボタンの経路にも nonce が要る: nonce が違う直接 POST は 403 で、R2C へは届かない。
test( 'a request without a valid nonce is refused and never reaches R2C', async ( { page, baseURL } ) => {
	await connect( page, baseURL, 'mock_resend_nonce_key' );
	const before = await mockHits( baseURL );

	const res = await page.request.post( `${ baseURL }/wp-admin/admin-ajax.php`, {
		form: { action: 'r2c_ai_concierge_resend_invite', nonce: 'not-a-valid-nonce' },
	} );

	expect( res.status() ).toBe( 403 );
	expect( await mockHits( baseURL ) ).toBe( before );
} );
