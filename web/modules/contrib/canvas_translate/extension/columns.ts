import type { DashboardItem, Language } from './types.ts';

/**
 * The languages the dashboard shows a translation column for.
 *
 * Every non-default language is always a column. The site default language is a
 * column too when at least one listed item was authored in a different language
 * — those items still need a translation *into* the site default, and without
 * the column there is no way to open one. When every item is sourced in the
 * site default, the column would only ever hold source cells, so it is omitted.
 *
 * The item list is the loaded (searched/filtered) one, so the columns describe
 * the rows actually on screen — same scoping as the per-language rail.
 */
export function targetLanguages(
  languages: readonly Language[] = [],
  items: readonly DashboardItem[] = [],
): Language[] {
  const nonDefault = languages.filter((l) => !l.isDefault);
  const defaultLanguage = languages.find((l) => l.isDefault);
  if (!defaultLanguage) return nonDefault;
  const hasNonDefaultSource = items.some((item) => item.sourceLangcode !== defaultLanguage.id);
  return hasNonDefaultSource ? [...nonDefault, defaultLanguage] : nonDefault;
}
