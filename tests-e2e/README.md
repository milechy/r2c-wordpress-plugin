# E2E tests

Playwright tests that drive a real WordPress admin (via
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)) to verify
this plugin's own behavior — connect/disconnect, settings save, front-end
widget output, resilience when R2C is unreachable.

## Scope: this plugin only

These tests never talk to the real `api.r2c.biz`, and never involve
commerce-faq-tasks (the R2C backend), CopilotUI, or a real R2C account.
`tests-e2e/mu-plugins/r2c-api-base-override.php` points
`R2C_AI_CONCIERGE_API_BASE` at a mock API instead —
`tests-e2e/mu-plugins/r2c-mock-api.php`, a small REST-route mock running on
the *same* WordPress install, so `wp_remote_request()` reaches it without
any container networking.

What that means is out of scope here, and covered by commerce-faq-tasks'
own test suite instead:

- Real site-ownership verification (provisioning always "succeeds" against
  whatever the mock is configured to return)
- Billing / usage tracking / cost guards
- CopilotUI's own screens (only *this plugin's reaction* to a value CopilotUI
  changed is tested — see `specs/settings-sync.spec.js`)
- `get_embed_code` not suggesting a manual embed for WP-provisioned tenants
  (that logic lives in commerce-faq-tasks' `actionExecutor.ts`)

See `docs/WORDPRESS_PLUGIN_REQUIREMENTS.md` §7 (commerce-faq-tasks repo) for
the full B/C/D/E acceptance-condition list; the items above are the ones a
WP-only E2E suite can actually exercise.

## Running locally

```bash
npm ci
npx playwright install --with-deps chromium
npm run e2e:start   # starts wp-env and activates the plugin
npm run e2e         # runs the Playwright suite
npm run e2e:destroy # tears the wp-env containers down when done
```

`npm run e2e:start` leaves wp-env running so you can re-run `npm run e2e`
repeatedly while iterating; each spec resets the mock API's state itself
(`resetMock()` in `helpers/wp-admin.js`), so tests don't need a fresh
environment between runs.

## Mock API control

`tests-e2e/mu-plugins/r2c-mock-api.php` registers `/wp-json/r2c-mock/v1/...`
routes shaped like the real `/v1/public/wp/...` API, plus two test-only
control routes specs call through `helpers/wp-admin.js`:

- `POST /wp-json/r2c-mock/v1/__test__/reset` — resets all mock state
- `POST /wp-json/r2c-mock/v1/__test__/configure` — see that file's
  `handle_test_configure()` doc comment for the supported keys
  (`poll_attempts_until_provisioned`, `forced_poll_status`,
  `force_settings_down`, `seed_api_key`, `settings`)

## Not part of the distributed plugin

Everything under `tests-e2e/`, plus `.wp-env.json`, `package.json`,
`playwright.config.js`, is listed in `.distignore` and excluded from the
wordpress.org build the same way `tests/` (the PHPUnit suite) is.
