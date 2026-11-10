import { test, expect } from './fixture';
import { findContentPageId, getRow, getTranslationRows } from './api';
import { spa, waitForIframe, publishAll, copySourceToEmpty } from './ui';
import {
  FORMAT_RICH_PAGE_TITLE,
  FORMAT_RICH_SOURCE_HTML,
  INLINE_RICH_PAGE_TITLE,
  LANGCODE,
  RICH_PAGE_TITLE,
} from './constants';

// cspell:ignore Texte traduit

/**
 * Rich-text translation E2E (against the sandboxed Drupal install).
 *
 * This is the path that CANNOT be kernel-tested: an SDC prop backed by a
 * multi-property field (rich text `{value, format}`, a link, an image…) routes
 * through canvas core's component-inputs extractor, which trips a dev-mode
 * typed-config assertion when run in-process. The seeded fixture uses a code
 * component (which avoids that path), so the write → publish guarantee is
 * exercised here. The guard is data-loss: the translated rich text must persist
 * AND keep its text format, and the non-translatable `variant` enum must stay
 * out of the translatable set.
 */
test('A typed rich-text translation persists with its format and shared enum', async ({ page }) => {
  const pageId = await findContentPageId(page, RICH_PAGE_TITLE);

  await page.goto(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}`);
  await waitForIframe(page);
  const frame = spa(page);
  await expect(frame.getByRole('button', { name: 'Review & publish' })).toBeVisible({ timeout: 30_000 });

  // The rich-text field renders in a CKEditor instance — proof the extraction
  // path ran against a real page with a `text_long` ({value, format}) prop.
  const editable = frame.locator('.ck-editor__editable').first();
  await expect(editable).toBeVisible({ timeout: 15_000 });

  // Replace its content with a translation, autosave, and publish.
  await editable.click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.type('Texte traduit');
  await expect(frame.getByText('Saved')).toBeVisible({ timeout: 15_000 });
  await publishAll(frame);

  // Backend truth (via the API): the translated rich text persisted as the
  // target AND kept its text format (only the value leaf moved).
  const textRow = await getRow(page, pageId, LANGCODE, 'components.0.text.value');
  expect(textRow.target).toContain('Texte traduit');
  expect(textRow.format).toBe('canvas_html_block');

  // The non-translatable `variant` enum is never offered for translation.
  const rows = await getTranslationRows(page, pageId, LANGCODE);
  expect(rows.some((r) => r.key === 'components.0.variant')).toBe(false);
});

/**
 * Rich text is edited in the format's own editor (Drupal core's CKEditor 5),
 * so markup the format allows — a heading, a managed link's data-entity-*
 * attributes, a phrase in another language, an embedded media item — survives
 * copy source → edit → publish, and the toolbar offers the format's own
 * buttons, including core's media library.
 */
test('Rich text keeps the markup its format allows', async ({ page }) => {
  const pageId = await findContentPageId(page, FORMAT_RICH_PAGE_TITLE);

  await page.goto(`/canvas/app/canvas_translate/page/${pageId}/${LANGCODE}`);
  await waitForIframe(page);
  const frame = spa(page);
  await expect(frame.getByRole('button', { name: 'Review & publish' })).toBeVisible({ timeout: 30_000 });

  await copySourceToEmpty(frame);
  const editable = frame.locator('.ck-editor__editable');
  await expect(editable.locator('h2')).toHaveText('Heading');
  await expect(frame.locator('.ck-heading-dropdown')).toBeVisible();
  await expect(frame.getByRole('textbox', { name: 'Translation HTML' })).toHaveCount(0);
  // The embed is a live media widget, previewed through core's media route.
  await expect(editable.locator('.drupal-media img')).toHaveAttribute('alt', 'Druplicon');
  // Core's media library opens as a dialog inside the extension.
  await frame.getByRole('button', { name: 'Insert Media' }).click();
  await expect(frame.getByRole('dialog', { name: 'Add or select media' })).toBeVisible();
  await page.keyboard.press('Escape');

  await editable.locator('h2').click();
  await page.keyboard.press('End');
  await page.keyboard.press('Shift+Home');
  await page.keyboard.type('Titre');
  await expect(frame.getByText('Saved')).toBeVisible({ timeout: 15_000 });
  await publishAll(frame);

  const textRow = await getRow(page, pageId, LANGCODE, 'components.0.text.value');
  expect(textRow.target).toBe(FORMAT_RICH_SOURCE_HTML.replace('Heading', 'Titre'));
});

/**
 * Opening a page with a second text format, after a first format's editor is
 * loaded, loads only what is missing: core is not loaded twice, and the second
 * format still gets its own editor rather than the HTML fallback.
 */
test('A second text format loads its editor without reloading core', async ({ page }) => {
  const firstId = await findContentPageId(page, FORMAT_RICH_PAGE_TITLE);
  const secondId = await findContentPageId(page, INLINE_RICH_PAGE_TITLE);

  await page.goto(`/canvas/app/canvas_translate/page/${firstId}/${LANGCODE}`);
  await waitForIframe(page);
  const frame = spa(page);
  await expect(frame.locator('.ck-editor__editable')).toBeVisible({ timeout: 30_000 });

  const extension = page.frames().find((f) => f !== page.mainFrame() && f.url().includes('canvas_translate'));
  expect(extension).toBeDefined();
  const scripts = () => extension!.evaluate(() => document.querySelectorAll('script[src*="core/misc/drupal.js"]').length);
  expect(await scripts()).toBe(1);

  // Navigate inside the extension's router, so the iframe and the editors it
  // already loaded stay in place.
  await extension!.evaluate(([id, langcode]) => {
    window.location.hash = `#/page/${id}/${langcode}`;
  }, [secondId, LANGCODE] as const);
  await expect(frame.getByText(INLINE_RICH_PAGE_TITLE).first()).toBeVisible({ timeout: 15_000 });
  // The field is in the second format's own editor (no heading dropdown, unlike
  // the first format), and copied source goes into it.
  await copySourceToEmpty(frame);
  const editable = frame.locator('.ck-editor__editable');
  await expect(editable.locator('strong')).toHaveText('inline');
  await expect(frame.locator('.ck-heading-dropdown')).toHaveCount(0);
  await expect(frame.getByRole('textbox', { name: 'Translation HTML' })).toHaveCount(0);
  expect(await scripts()).toBe(1);
});
