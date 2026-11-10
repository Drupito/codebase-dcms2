import { test, expect } from './fixture';
import { findContentPageId, getRow } from './api';
import { spa, waitForIframe, publishAll } from './ui';
import { LANGCODE, PLAIN_PAGE_TITLE } from './constants';

/**
 * Plain-field translation workflow E2E (against the sandboxed Drupal install).
 *
 * Smoke-tests the dashboard + editor + publish flow for a page's simple string
 * fields (title / description): the seeded page starts untranslated, a typed
 * translation autosaves, and publishing persists it. The two tests run in order
 * and share the seeded plain page, so the first must observe it before the
 * second translates it.
 */

const FR_TITLE = 'Page de traduction E2E';

test.describe('Canvas Translate plain-field workflow', () => {
  test('dashboard shows a seeded page as Not translated', async ({ page }) => {
    await page.goto('/canvas/app/canvas_translate');
    await waitForIframe(page);
    const frame = spa(page);

    const row = frame.locator('tr', { hasText: PLAIN_PAGE_TITLE });
    await expect(row).toBeVisible({ timeout: 30_000 });
    await expect(row.getByText('Not translated')).toBeVisible();
  });

  test('translating the title autosaves, publishes, and persists', async ({ page }) => {
    const pageId = await findContentPageId(page, PLAIN_PAGE_TITLE);

    await page.goto(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}`);
    await waitForIframe(page);
    const frame = spa(page);
    await expect(frame.getByRole('button', { name: 'Review & publish' })).toBeVisible({ timeout: 30_000 });

    // Each row shows a read-only source plus an editable target (placeholder
    // "Add translation…"); the first target field is the page title. Type a
    // translation and wait for the debounced autosave to report "Saved".
    const titleTarget = frame.getByPlaceholder('Add translation').first();
    await expect(titleTarget).toBeVisible();
    await titleTarget.fill(FR_TITLE);
    await expect(frame.getByText('Saving…')).toBeVisible();
    await expect(frame.getByText('Saved')).toBeVisible({ timeout: 15_000 });

    await publishAll(frame);

    // Backend truth (via the API): the published translation carries the title.
    const titleRow = await getRow(page, pageId, LANGCODE, 'title');
    expect(titleRow.target).toBe(FR_TITLE);
  });
});
