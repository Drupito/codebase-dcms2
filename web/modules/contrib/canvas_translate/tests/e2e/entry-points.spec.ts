import { test, expect } from './fixture';
import { findContentPageId } from './api';
import { spa, waitForIframe } from './ui';
import { LANGCODE, PLAIN_PAGE_TITLE } from './constants';

/**
 * Entry points E2E: core's Translate operation/tab (the content_translation
 * overview route) lands on the page's own overview in the extension, and a
 * language row there opens that page's editor.
 */
test('Translate opens the page overview, and a language opens its editor', async ({ page }) => {
  const pageId = await findContentPageId(page, PLAIN_PAGE_TITLE);

  await page.goto(`/page/${pageId}/translations`);
  await expect(page).toHaveURL(new RegExp(`/canvas/app/canvas_translate/page/${pageId}$`));
  await waitForIframe(page);
  const frame = spa(page);

  await expect(frame.getByRole('heading', { name: PLAIN_PAGE_TITLE })).toBeVisible({ timeout: 30_000 });
  const row = frame.getByRole('link', { name: /French/ });
  await expect(row).toBeVisible();

  await row.click();
  await expect(frame.getByRole('button', { name: 'Review & publish' })).toBeVisible({ timeout: 30_000 });
  await expect(page).toHaveURL(new RegExp(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}$`));
});

/**
 * The Canvas editor's language switcher offers "Add translation" for a missing
 * language, which opens that language's editor in a new tab. Runs before the
 * specs that publish a French translation of this page.
 */
test('the Canvas language switcher opens the translation editor', async ({ page }) => {
  const pageId = await findContentPageId(page, PLAIN_PAGE_TITLE);

  await page.goto(`/canvas/editor/canvas_page/${pageId}`);
  await page.getByTestId('language-select-trigger').click();
  await page.getByRole('button', { name: 'More options for French' }).click();
  const [popup] = await Promise.all([
    page.waitForEvent('popup'),
    page.getByRole('button', { name: 'Add translation' }).click(),
  ]);

  await expect(popup).toHaveURL(new RegExp(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}$`));
  await waitForIframe(popup);
  await expect(spa(popup).getByRole('button', { name: 'Review & publish' })).toBeVisible({ timeout: 30_000 });
});
