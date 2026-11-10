import type { ConfigType, DashboardResponse, Kind } from './types.ts';

/** One pending draft, flattened from the dashboard's per-language cells. */
export interface DraftRow {
  kind: Kind;
  /** Config sub-type, only when kind === 'config'. @see ConfigType */
  type?: ConfigType;
  /** Numeric for content pages; string config id for config entities. */
  id: number | string;
  label: string;
  langcode: string;
  langName: string;
  percent: number;
  /** Whether a reviewer has approved this draft. */
  approved: boolean;
}

/** Stable identity for a draft across the queue and the review screen. */
export function draftRowKey(r: Pick<DraftRow, 'kind' | 'id' | 'langcode'>): string {
  return `${r.kind}:${r.id}:${r.langcode}`;
}

/** Route to the read-only review screen for a draft. */
export function reviewPath(r: Pick<DraftRow, 'kind' | 'type' | 'id' | 'langcode'>): string {
  return r.kind === 'config'
    ? `/review/config/${r.type}/${encodeURIComponent(String(r.id))}/${r.langcode}`
    : `/review/page/${r.id}/${r.langcode}`;
}

/** Route to the full editor for a draft. */
export function editorPath(r: Pick<DraftRow, 'kind' | 'type' | 'id' | 'langcode'>): string {
  return r.kind === 'config'
    ? `/config/${r.type}/${encodeURIComponent(String(r.id))}/${r.langcode}`
    : `/page/${r.id}/${r.langcode}`;
}

/**
 * Flattens a dashboard response to the ordered list of pending drafts (one row
 * per item × language whose status is 'draft'). Shared by the review queue and
 * the review screen's prev/next so both walk the items in the same order.
 */
export function flattenDrafts(data: DashboardResponse | null): DraftRow[] {
  if (!data) return [];
  const langName = (lc: string) => data.languages.find((l) => l.id === lc)?.name ?? lc;
  const out: DraftRow[] = [];
  data.items.forEach((item) => {
    Object.entries(item.translations).forEach(([lc, state]) => {
      if (state.status !== 'draft') return;
      out.push({
        kind: item.kind,
        type: item.kind === 'config' ? (item.type as ConfigType) : undefined,
        id: item.kind === 'config' ? String(item.id) : Number(item.id),
        label: item.label,
        langcode: lc,
        langName: langName(lc),
        percent: state.percent,
        approved: state.approved ?? false,
      });
    });
  });
  return out;
}
