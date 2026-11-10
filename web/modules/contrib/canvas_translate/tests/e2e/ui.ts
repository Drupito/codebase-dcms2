import { expect, type FrameLocator, type Page } from '@playwright/test';

/**
 * Shared helpers for driving the Canvas Translate SPA, which runs inside an
 * iframe in the Canvas app shell with a stable id derived from the extension id.
 */

const IFRAME = 'iframe[id="canvas-extension-page-canvas_translate"]';

/** Frame locator for the SPA running inside the Canvas app-shell iframe. */
export function spa(page: Page): FrameLocator {
  return page.frameLocator(IFRAME);
}

/** Wait until the iframe element is attached in the shell. */
export async function waitForIframe(page: Page): Promise<void> {
  await page.locator(IFRAME).waitFor({ state: 'attached', timeout: 30_000 });
}

/**
 * Drive the editor's publish flow: the "Review & publish" button opens the
 * review panel; "Publish all" commits every pending draft and the panel then
 * shows the "All caught up" empty state.
 */
export async function publishAll(frame: FrameLocator): Promise<void> {
  await frame.getByRole('button', { name: 'Review & publish' }).click();
  const publishAllBtn = frame.getByRole('button', { name: 'Publish all' });
  await expect(publishAllBtn).toBeEnabled({ timeout: 15_000 });
  await publishAllBtn.click();
  await expect(frame.getByText('All caught up')).toBeVisible({ timeout: 20_000 });
}

/** Click "Copy source to empty", which lives in the "More actions" overflow menu. */
export async function copySourceToEmpty(frame: FrameLocator): Promise<void> {
  await frame.getByRole('button', { name: 'More actions' }).click();
  await frame.getByRole('menuitem', { name: 'Copy source to empty' }).click();
}
