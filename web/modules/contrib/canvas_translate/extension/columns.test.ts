import { describe, it, expect } from 'vitest';
import { targetLanguages } from './columns.ts';
import type { DashboardItem, Language } from './types.ts';

// ---- Minimal fixture helpers ------------------------------------------------

const EN: Language = { id: 'en', name: 'English', direction: 'ltr', isDefault: true };
const HU: Language = { id: 'hu', name: 'Hungarian', direction: 'ltr', isDefault: false };
const FR: Language = { id: 'fr', name: 'French', direction: 'ltr', isDefault: false };

const LANGUAGES = [EN, HU, FR];

function item(sourceLangcode: string, label = 'Page'): DashboardItem {
  return { kind: 'content', type: 'canvas_page', id: 1, label, sourceLangcode, translations: {} };
}

const ids = (langs: Language[]) => langs.map((l) => l.id);

describe('targetLanguages', () => {
  it('offers the site default as a column when an item is sourced elsewhere', () => {
    expect(ids(targetLanguages(LANGUAGES, [item('hu')]))).toEqual(['hu', 'fr', 'en']);
  });

  it('offers it once when several items are sourced elsewhere', () => {
    const items = [item('hu', 'A'), item('fr', 'B'), item('hu', 'C')];
    expect(ids(targetLanguages(LANGUAGES, items))).toEqual(['hu', 'fr', 'en']);
  });

  it('omits the site default when every item is sourced in it', () => {
    const items = [item('en', 'A'), item('en', 'B')];
    expect(ids(targetLanguages(LANGUAGES, items))).toEqual(['hu', 'fr']);
  });

  it('omits the site default when there are no items', () => {
    expect(ids(targetLanguages(LANGUAGES, []))).toEqual(['hu', 'fr']);
  });

  it('always includes every non-default language', () => {
    for (const items of [[], [item('en')], [item('hu')]]) {
      const result = ids(targetLanguages(LANGUAGES, items));
      expect(result).toEqual(expect.arrayContaining(['hu', 'fr']));
    }
  });

  it('falls back to the non-default languages when none is marked default', () => {
    expect(ids(targetLanguages([HU, FR], [item('hu')]))).toEqual(['hu', 'fr']);
  });

  it('is safe on missing data', () => {
    expect(targetLanguages()).toEqual([]);
    expect(ids(targetLanguages(LANGUAGES))).toEqual(['hu', 'fr']);
  });
});
