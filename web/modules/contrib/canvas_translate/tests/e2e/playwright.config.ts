import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the Canvas Translate module UI e2e suite.
 *
 * Site lifecycle, login and teardown live in the fixtures (see fixture.ts),
 * built on Drupal core's `@drupal/playwright`: one seeded sandbox per worker,
 * reached over the site's base URL (`DRUPAL_TEST_BASE_URL`) by carrying the
 * SIMPLETEST_USER_AGENT cookie. `ignoreHTTPSErrors` covers ddev's self-signed
 * cert when the base URL is HTTPS.
 */
export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.ts/,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.DRUPAL_TEST_BASE_URL ?? 'http://localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
