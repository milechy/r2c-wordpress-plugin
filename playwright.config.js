// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

// tests share one wp-env instance and the mock API's stored state (reset
// per-test via resetMock(), not per-worker) — parallel workers would race
// on that shared state, so this suite intentionally runs serially.
module.exports = defineConfig( {
	testDir: './tests-e2e/specs',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
