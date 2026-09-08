// @ts-check
const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, connectViaManualKey, resetMock } = require( '../helpers/wp-admin' );

// FR-10's "Add a specific page" / "Add a post type" quick-add buttons
// (assets/js/admin-settings.js initExcludedPagesHelpers/appendExcludedPattern)
// had no coverage anywhere else — every other spec only edits
// #r2c-excluded-patterns directly.
//
// This install defaults to WordPress's own "Plain" permalink structure.
// Under Plain, get_permalink()/get_post_type_archive_link() fall back to
// `?page_id=`/`?post_type=` query strings, and every single page/post type
// resolves to the exact same "/" path — page_id_to_path_map() and
// excludable_post_type_patterns() (class-r2c-ai-concierge-settings-page.php) used to
// turn that into the pattern "/" or "/*" regardless of which page/post
// type was actually selected, which would exclude the entire site rather
// than the one page picked. Both helpers now skip any entry that only
// resolves to "/", and render_excluded_pages_section() replaces the
// pickers with an explanatory note when nothing is left to offer.
//
// ★What this file does NOT test, and why★ The positive path (quick-add
// actually working under a "pretty" permalink structure) is covered by
// tests/test-settings-page-page-id-to-path-map.php and
// tests/test-settings-page-helpers.php instead of here. Actually switching
// this wp-env install's live permalink structure via wp-admin was tried
// and reproducibly triggers a WordPress-core-level fatal in this container
// afterwards ("Call to a member function using_index_permalinks() on null"
// in wp-includes/rest-api.php, on every subsequent request) — the same
// class of rewrite-rule fragility this container already has (see
// tests-e2e/README.md's note on why .wp-env.json pins no phpVersion), not
// a bug in this plugin. Re-attempting that switch here would make the
// whole suite unreliable rather than testing anything about the fix.
test( 'the post-type/page pickers are replaced by an explanatory note instead of a footgun', async ( { page, baseURL } ) => {
	await resetMock( baseURL, { seed_api_key: 'mock_quickadd_plain_key' } );
	await loginAsAdmin( page, baseURL );
	await connectViaManualKey( page, baseURL, 'mock_quickadd_plain_key' );

	await expect( page.locator( '#r2c-excluded-post-type' ) ).toHaveCount( 0 );
	await expect( page.locator( '#r2c-excluded-page-picker' ) ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).toContainText( 'requires a "pretty" permalink structure' );
} );
