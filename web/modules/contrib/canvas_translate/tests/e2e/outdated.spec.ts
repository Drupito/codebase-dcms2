import { test, expect } from './fixture';
import { findContentPageId, getRow } from './api';
import { spa, waitForIframe } from './ui';
import { LANGCODE, OUTDATED_PAGE_TITLE } from './constants';

// cspell:ignore périmée

/**
 * Guided source-change review E2E (against the sandboxed Drupal install).
 *
 * The seeded "outdated" page has a published French translation whose source
 * description was then edited, so it loads outdated with exactly one changed
 * field. Covers the dashboard staleness chip, the editor's review banner +
 * in-place Keep, and "Mark as reviewed" clearing the outdated state without
 * rewriting the translation.
 */
test.describe('Canvas Translate guided review', () => {
  test('dashboard flags the outdated page with a changed-string count', async ({ page }) => {
    await page.goto('/canvas/app/canvas_translate');
    await waitForIframe(page);
    const frame = spa(page);

    const row = frame.locator('tr', { hasText: OUTDATED_PAGE_TITLE });
    await expect(row).toBeVisible({ timeout: 30_000 });
    // The outdated badge carries the magnitude ("Outdated · 1 changed"); the
    // count is unique to an outdated cell, so asserting it proves both. (Plain
    // "Outdated" would also match the page title "E2E Outdated Page".)
    await expect(row.getByText(/1 changed/)).toBeVisible();
  });

  test('review banner steps through changes and "Mark as reviewed" clears outdated', async ({ page }) => {
    const pageId = await findContentPageId(page, OUTDATED_PAGE_TITLE);
    await page.goto(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}`);
    await waitForIframe(page);
    const frame = spa(page);

    // The summary banner acknowledges the drift and offers a guided review.
    await expect(frame.getByText(/changed in the source since you last published/)).toBeVisible({ timeout: 30_000 });
    await frame.getByRole('button', { name: 'Review changes' }).click();

    // The changed field shows the old→new diff with an in-place "Keep" action.
    await expect(frame.getByText('Source changed since last translation')).toBeVisible();
    await frame.getByRole('button', { name: 'Keep translation' }).first().click();

    // Mark the whole translation reviewed; the editor reloads up to date.
    await frame.getByRole('button', { name: 'Mark as reviewed' }).first().click();
    await expect(frame.getByText('Up to date')).toBeVisible({ timeout: 20_000 });

    // Backend truth: no longer outdated, and the translation values are intact.
    const res = await page.request.get(`/canvas-translate/api/items/canvas_page/${pageId}/${LANGCODE}`);
    expect(res.ok(), `getTranslation failed: ${res.status()}`).toBe(true);
    const doc = (await res.json()) as { status: string };
    expect(doc.status).toBe('up_to_date');
    const descRow = await getRow(page, pageId, LANGCODE, 'description');
    expect(descRow.target).toBe('Description périmée FR.');
  });
});
