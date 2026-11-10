import { describe, it, expect } from 'vitest';
import { flattenDrafts, draftRowKey, reviewPath, editorPath } from './drafts.ts';
import type { DashboardResponse } from './types.ts';

// cspell:ignore Fbaz

// ---- Minimal fixture helpers ------------------------------------------------

function makeDashboard(overrides: Partial<DashboardResponse> = {}): DashboardResponse {
  return {
    languages: [
      { id: 'fi', name: 'Finnish', direction: 'ltr', isDefault: false },
      { id: 'sv', name: 'Swedish', direction: 'ltr', isDefault: false },
    ],
    total: 1,
    items: [],
    ...overrides,
  };
}

const BASE_TRANSLATION_DRAFT = {
  status: 'draft' as const,
  hasDraft: true,
  percent: 75,
  changed: null,
};

const BASE_TRANSLATION_UP_TO_DATE = {
  status: 'up_to_date' as const,
  hasDraft: false,
  percent: 100,
  changed: null,
};

// ---- flattenDrafts ----------------------------------------------------------

describe('flattenDrafts', () => {
  it('returns [] when data is null', () => {
    expect(flattenDrafts(null)).toEqual([]);
  });

  it('returns [] when there are no items', () => {
    expect(flattenDrafts(makeDashboard())).toEqual([]);
  });

  it('includes only cells with status === "draft"', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 42,
          label: 'Home',
          sourceLangcode: 'en',
          translations: {
            fi: BASE_TRANSLATION_DRAFT,
            sv: BASE_TRANSLATION_UP_TO_DATE,
          },
        },
      ],
    });
    const rows = flattenDrafts(data);
    expect(rows).toHaveLength(1);
    expect(rows[0].langcode).toBe('fi');
  });

  it('content item: id is a number, type is undefined', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 99,
          label: 'About',
          sourceLangcode: 'en',
          translations: { fi: BASE_TRANSLATION_DRAFT },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(typeof row.id).toBe('number');
    expect(row.id).toBe(99);
    expect(row.type).toBeUndefined();
    expect(row.kind).toBe('content');
  });

  it('config item: id is a string, type is set', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'config',
          type: 'content_template',
          id: 'node.article.full',
          label: 'Article full',
          sourceLangcode: 'en',
          translations: { fi: BASE_TRANSLATION_DRAFT },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(typeof row.id).toBe('string');
    expect(row.id).toBe('node.article.full');
    expect(row.type).toBe('content_template');
    expect(row.kind).toBe('config');
  });

  it('approved defaults to false when absent from translation state', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 1,
          label: 'X',
          sourceLangcode: 'en',
          translations: { fi: { ...BASE_TRANSLATION_DRAFT } },
        },
      ],
    });
    // approved not set in BASE_TRANSLATION_DRAFT → should default to false
    const [row] = flattenDrafts(data);
    expect(row.approved).toBe(false);
  });

  it('approved is true when translation state has approved: true', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 2,
          label: 'Y',
          sourceLangcode: 'en',
          translations: { fi: { ...BASE_TRANSLATION_DRAFT, approved: true } },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(row.approved).toBe(true);
  });

  it('langName falls back to the langcode when language is not in languages list', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 3,
          label: 'Z',
          sourceLangcode: 'en',
          // 'de' is NOT in the languages list of the fixture
          translations: { de: BASE_TRANSLATION_DRAFT },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(row.langName).toBe('de');
  });

  it('langName is the full name when language is in the list', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 4,
          label: 'W',
          sourceLangcode: 'en',
          translations: { fi: BASE_TRANSLATION_DRAFT },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(row.langName).toBe('Finnish');
  });

  it('percent is carried through from the translation state', () => {
    const data = makeDashboard({
      items: [
        {
          kind: 'content',
          type: 'canvas_page',
          id: 5,
          label: 'V',
          sourceLangcode: 'en',
          translations: { fi: { ...BASE_TRANSLATION_DRAFT, percent: 50 } },
        },
      ],
    });
    const [row] = flattenDrafts(data);
    expect(row.percent).toBe(50);
  });
});

// ---- draftRowKey ------------------------------------------------------------

describe('draftRowKey', () => {
  it('is stable and includes kind, id, and langcode', () => {
    const key = draftRowKey({ kind: 'content', id: 42, langcode: 'fi' });
    expect(key).toBe('content:42:fi');
  });

  it('is distinct across different kinds', () => {
    const a = draftRowKey({ kind: 'content', id: 1, langcode: 'fi' });
    const b = draftRowKey({ kind: 'config', id: 1, langcode: 'fi' });
    expect(a).not.toBe(b);
  });

  it('is distinct across different ids', () => {
    const a = draftRowKey({ kind: 'content', id: 1, langcode: 'fi' });
    const b = draftRowKey({ kind: 'content', id: 2, langcode: 'fi' });
    expect(a).not.toBe(b);
  });

  it('is distinct across different langcodes', () => {
    const a = draftRowKey({ kind: 'content', id: 1, langcode: 'fi' });
    const b = draftRowKey({ kind: 'content', id: 1, langcode: 'sv' });
    expect(a).not.toBe(b);
  });

  it('works for string config ids', () => {
    const key = draftRowKey({ kind: 'config', id: 'node.article.full', langcode: 'fi' });
    expect(key).toBe('config:node.article.full:fi');
  });
});

// ---- reviewPath -------------------------------------------------------------

describe('reviewPath', () => {
  it('content item → /review/page/:id/:langcode', () => {
    const path = reviewPath({ kind: 'content', type: undefined, id: 42, langcode: 'fi' });
    expect(path).toBe('/review/page/42/fi');
  });

  it('config item → /review/config/:type/:encodedId/:langcode', () => {
    const path = reviewPath({ kind: 'config', type: 'content_template', id: 'node.article.full', langcode: 'fi' });
    expect(path).toBe('/review/config/content_template/node.article.full/fi');
  });

  it('config id with dots is encodeURIComponent-encoded', () => {
    const path = reviewPath({ kind: 'config', type: 'page_region', id: 'node.article.full', langcode: 'sv' });
    // dots encode to %2E in full encodeURIComponent, but encodeURIComponent
    // does NOT encode dots — they are unreserved chars. Verify the actual shape.
    expect(path).toContain('node.article.full');
    expect(path).toBe('/review/config/page_region/node.article.full/sv');
  });

  it('config id with special chars is percent-encoded', () => {
    // A hypothetical id with a space or slash (less common, but encodeURIComponent covers it)
    const path = reviewPath({ kind: 'config', type: 'page_region', id: 'foo bar/baz', langcode: 'fi' });
    expect(path).toBe('/review/config/page_region/foo%20bar%2Fbaz/fi');
  });
});

// ---- editorPath -------------------------------------------------------------

describe('editorPath', () => {
  it('content item → /page/:id/:langcode', () => {
    const path = editorPath({ kind: 'content', type: undefined, id: 7, langcode: 'sv' });
    expect(path).toBe('/page/7/sv');
  });

  it('config item → /config/:type/:encodedId/:langcode', () => {
    const path = editorPath({ kind: 'config', type: 'content_template', id: 'node.article.full', langcode: 'fi' });
    expect(path).toBe('/config/content_template/node.article.full/fi');
  });

  it('config id with special chars is percent-encoded', () => {
    const path = editorPath({ kind: 'config', type: 'page_region', id: 'foo bar/baz', langcode: 'sv' });
    expect(path).toBe('/config/page_region/foo%20bar%2Fbaz/sv');
  });
});
