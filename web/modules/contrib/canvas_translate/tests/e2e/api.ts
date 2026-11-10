import { expect, type Page } from '@playwright/test';

/**
 * Read-side helpers backed by the Canvas Translate JSON API. Requests reuse the
 * page's context (so they carry the auth + SIMPLETEST_USER_AGENT cookies and hit
 * the sandbox), giving the specs backend truth without any DB/drush access.
 */

export interface FieldRow {
  key: string;
  source: string;
  target: string;
  format: string | null;
}

/** Resolves a seeded content page's id by its label, via the dashboard. */
export async function findContentPageId(page: Page, label: string): Promise<number> {
  const res = await page.request.get('/canvas-translate/api/items');
  expect(res.ok(), `dashboard request failed: ${res.status()}`).toBe(true);
  const body = (await res.json()) as {
    items: Array<{ kind: string; id: number; label: string }>;
  };
  const item = body.items.find((i) => i.kind === 'content' && i.label === label);
  if (!item) {
    throw new Error(`findContentPageId: no content page labelled "${label}"`);
  }
  return item.id;
}

/** Returns the editor's side-by-side rows for one page + language, flattened. */
export async function getTranslationRows(
  page: Page,
  id: number,
  langcode: string,
): Promise<FieldRow[]> {
  const res = await page.request.get(
    `/canvas-translate/api/items/canvas_page/${id}/${langcode}`,
  );
  expect(res.ok(), `getTranslation failed: ${res.status()}`).toBe(true);
  const doc = (await res.json()) as { groups: Array<{ rows: FieldRow[] }> };
  return doc.groups.flatMap((g) => g.rows);
}

/** Returns one row by key, or fails the test if it is absent. */
export async function getRow(
  page: Page,
  id: number,
  langcode: string,
  key: string,
): Promise<FieldRow> {
  const rows = await getTranslationRows(page, id, langcode);
  const row = rows.find((r) => r.key === key);
  if (!row) {
    throw new Error(
      `getRow: no row "${key}" (have: ${rows.map((r) => r.key).join(', ')})`,
    );
  }
  return row;
}
