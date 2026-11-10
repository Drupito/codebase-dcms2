import { test as base, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { promisify } from 'node:util';
import { Drupal } from '@drupal/playwright';
import type { DrupalSite } from '@drupal/playwright';

/**
 * Playwright harness for the Canvas Translate e2e suite, built on Drupal core's
 * official `@drupal/playwright` package (the same one Canvas uses).
 *
 * `@drupal/playwright`'s own `Drupal.install()` installs a bare `minimal` site
 * with no way to run a PHP setup file, but this suite's fixtures (the `fr`
 * language, the translatable base fields, and the seeded pages/translations/
 * draft) are provisioned by CanvasTranslateTestSetup. So we install with
 * `test-site.php --setup-file` ourselves, then hand the resulting site to the
 * package's `Drupal` page object for the test cookie, login and teardown.
 *
 * Both locally (run inside ddev) and in CI the Drupal CLI is on PATH, so commands
 * run "bare"; in CI they run as `DRUPAL_TEST_WEBSERVER_USER` so the sandbox files
 * (notably the SQLite DB) are owned by the user Apache serves as.
 *
 * @see \Drupal\Tests\canvas_translate\TestSite\CanvasTranslateTestSetup
 */

const execFileAsync = promisify(execFile);

/**
 * Setup-file path, relative to the Drupal root. Local ddev has the module at
 * modules/contrib; drupal.org CI symlinks it under modules/custom, so the CI job
 * overrides this via CANVAS_TRANSLATE_SETUP_FILE.
 */
const SETUP_FILE =
  process.env.CANVAS_TRANSLATE_SETUP_FILE ??
  'modules/contrib/canvas_translate/tests/src/TestSite/CanvasTranslateTestSetup.php';

const BASE_URL = process.env.DRUPAL_TEST_BASE_URL ?? 'http://localhost';
const DB_URL =
  process.env.DRUPAL_TEST_DB_URL ??
  'sqlite://localhost/sites/default/files/.ht.sqlite';

/**
 * The Drupal root the CLI runs from: DRUPAL_TEST_DRUPAL_ROOT if set, else walk up
 * from the cwd looking for the docroot. Mirrors `@drupal/playwright`'s getRootDir
 * so our seed install resolves the root the same way login/teardown do.
 */
function drupalRoot(): string {
  if (process.env.DRUPAL_TEST_DRUPAL_ROOT) {
    return process.env.DRUPAL_TEST_DRUPAL_ROOT;
  }
  let dir = process.cwd();
  for (let i = 0; i < 15; i++) {
    if (existsSync(`${dir}/index.php`) && existsSync(`${dir}/core/lib/Drupal.php`)) {
      return dir;
    }
    dir = resolve(dir, '..');
  }
  throw new Error('Unable to find Drupal root directory.');
}

/**
 * Run a Drupal CLI command from the Drupal root, as DRUPAL_TEST_WEBSERVER_USER
 * when set. Mirrors `@drupal/playwright`'s own exec (which login/teardown use) so
 * the seed install runs as the same user and from the same directory.
 */
async function exec(command: string): Promise<string> {
  const user = process.env.DRUPAL_TEST_WEBSERVER_USER;
  const full = user ? `sudo -u ${user} ${command}` : command;
  const { stdout } = await execFileAsync('bash', ['-c', full], {
    cwd: drupalRoot(),
    maxBuffer: 64 * 1024 * 1024,
  });
  return stdout;
}

/**
 * Installs an isolated sandbox seeded by CanvasTranslateTestSetup and returns it
 * in `@drupal/playwright`'s DrupalSite shape so the Drupal page object can drive
 * it.
 */
async function installSeededSite(): Promise<DrupalSite> {
  const stdout = await exec(
    `php core/scripts/test-site.php install --no-interaction` +
      ` --setup-file ${SETUP_FILE} --install-profile minimal` +
      ` --base-url ${BASE_URL} --db-url ${DB_URL} --json`,
  );
  const line = stdout
    .split('\n')
    .map((l) => l.trim())
    .reverse()
    .find((l) => l.startsWith('{'));
  if (!line) {
    throw new Error(`installSeededSite: no JSON in test-site.php output:\n${stdout}`);
  }
  const data = JSON.parse(line) as {
    db_prefix: string;
    user_agent: string;
    site_path: string;
  };
  return {
    dbPrefix: data.db_prefix,
    userAgent: data.user_agent,
    sitePath: data.site_path,
    url: BASE_URL,
    teardown: async () => {
      if (process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_TEARDOWN === 'true') {
        return;
      }
      await exec(
        `php core/scripts/test-site.php tear-down --no-interaction --db-url ${DB_URL} ${data.db_prefix}`,
      );
    },
  };
}

/**
 * One seeded sandbox per worker (installs are expensive and the specs each drive
 * their own seeded page), plus a per-test admin-authenticated Drupal page object.
 */
export const test = base.extend<{ drupal: Drupal }, { seededSite: DrupalSite }>({
  seededSite: [
    async ({}, use) => {
      const site = await installSeededSite();
      await use(site);
      await site.teardown();
    },
    { scope: 'worker', auto: true },
  ],
  drupal: [
    async ({ page, seededSite }, use) => {
      const drupal = new Drupal({ page, drupalSite: seededSite });
      await drupal.setTestCookie();
      await drupal.loginAsAdmin();
      await use(drupal);
    },
    { auto: true },
  ],
});

export { expect };
