// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	loginAsAdmin,
	gotoSettings,
	resetMock,
	connectViaManualKey,
} = require( '../helpers/wp-admin' );

// WP-UX-19: with the site language set to Japanese, the bundled
// languages/r2c-ai-concierge-ja.mo must actually be loaded — this plugin ships
// its own .mo (it is not only relying on translate.wordpress.org), and nothing
// else in the suite runs a non-English locale. The mock switches the locale
// with { locale: 'ja' } (reset puts it back to the default).

test( 'the bundled Japanese translation is loaded on the connect screen, including strings passed to JavaScript', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { locale: 'ja' } );
	await loginAsAdmin( page, baseURL );
	await gotoSettings( page, baseURL );

	const body = page.locator( 'body' );
	await expect( body ).toContainText( 'すでに R2C をご契約中の方' );
	await expect( body ).not.toContainText( 'I already have an R2C contract' );

	// admin-settings.js が表示する文言は wp_localize_script 経由(PHP の __())。
	await page.click( '#r2c-entry-existing summary' );
	await page.click( '#r2c-manual-button' );
	await expect( page.locator( '#r2c-manual-status' ) ).toContainText( '接続キーを入力してください' );
} );

test( 'the connected screen uses plain Japanese words, not "tenant" or "API key"', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { locale: 'ja', seed_api_key: 'mock_ja_key_0123456789' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_ja_key_0123456789' );

	const body = page.locator( 'body' );
	await expect( body ).toContainText( 'お店のID' );
	await expect( body ).toContainText( '接続キー' );
	await expect( body ).toContainText( '今週の状況' );
	await expect( body ).not.toContainText( 'テナント' );
	await expect( body ).not.toContainText( 'APIキー' );
	// 翻訳の抜け: 英語のまま残っていないこと。
	await expect( body ).not.toContainText( 'This week' );
	await expect( body ).not.toContainText( 'Store ID' );
} );
