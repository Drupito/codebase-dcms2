import { test, expect } from './fixture';
import { findContentPageId, getRow } from './api';
import { spa, waitForIframe } from './ui';
import { LANGCODE, REVIEW_DRAFT_TITLE, REVIEW_PAGE_TITLE } from './constants';

// cspell:ignore brouillon

/**
 * Review → approve → publish workflow E2E (against the sandboxed Drupal install).
 *
 * The review page is seeded with a published French translation plus a pending
 * draft that changes the title. This drives the staged pipeline: the queue lists
 * it as "Needs review", the review screen shows the published→draft change diff,
 * approving flips it to "Approved" (and pre-selects it), and "Publish selected"
 * ships the approved draft.
 */
test.describe('Canvas Translate review & approve workflow', () => {
  test('a seeded draft is reviewed, approved, and published', async ({ page }) => {
    const pageId = await findContentPageId(page, REVIEW_PAGE_TITLE);

    // The review queue lists the seeded draft as needing review.
    await page.goto('/canvas/app/canvas_translate/review');
    await waitForIframe(page);
    const frame = spa(page);
    const row = frame.locator('tr', { hasText: REVIEW_PAGE_TITLE });
    await expect(row).toBeVisible({ timeout: 30_000 });
    await expect(row.getByText('Needs review')).toBeVisible();

    // Open the review diff screen; the draft title shows as an added word in the
    // change diff (the published title is struck through beside it).
    await page.goto(`/canvas/app/canvas_translate/review/page/${pageId}/${LANGCODE}`);
    await expect(frame.getByText('(brouillon)')).toBeVisible({ timeout: 30_000 });

    // Approve it.
    await frame.getByRole('button', { name: 'Approve' }).click();

    // Back on the queue, the row is now approved and pre-selected.
    await page.goto('/canvas/app/canvas_translate/review');
    const approvedRow = frame.locator('tr', { hasText: REVIEW_PAGE_TITLE });
    await expect(approvedRow.getByText('Approved')).toBeVisible({ timeout: 30_000 });

    // Publish the approved (pre-selected) set.
    const publishSelected = frame.getByRole('button', { name: /Publish selected/ });
    await expect(publishSelected).toBeEnabled();
    await publishSelected.click();

    // Backend truth: the published translation now carries the draft title.
    await expect(async () => {
      const titleRow = await getRow(page, pageId, LANGCODE, 'title');
      expect(titleRow.target).toBe(REVIEW_DRAFT_TITLE);
    }).toPass({ timeout: 20_000 });
  });
});
