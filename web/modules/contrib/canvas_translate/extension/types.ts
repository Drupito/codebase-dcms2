export interface Language {
  id: string;
  name: string;
  direction: string;
  isDefault: boolean;
}

export type Status = 'source' | 'not_translated' | 'draft' | 'up_to_date' | 'outdated';

export interface TranslationState {
  status: Status;
  hasDraft: boolean;
  /** Whether a reviewer has approved this pending draft (review pipeline step). */
  approved?: boolean;
  changed: number | null;
  /**
   * How many source strings changed since this translation was last published.
   * Non-null only when status === 'outdated'; lets the dashboard show magnitude.
   */
  changedStrings?: number | null;
  /** Completeness: filled target strings / source strings, 0–100. */
  percent: number;
}

/** Whether an item is a content entity (canvas_page) or a config entity. */
export type Kind = 'content' | 'config';

/** Config entity sub-type, present only when kind === 'config'. */
export type ConfigType = 'content_template' | 'page_region' | 'page_variant';

export interface DashboardItem {
  kind: Kind;
  /** 'canvas_page' for content; a ConfigType for config. */
  type: string;
  /** Numeric for content pages; string config ID (e.g. node.article.full) for config. */
  id: number | string;
  label: string;
  sourceLangcode: string;
  translations: Record<string, TranslationState>;
}

export interface DashboardResponse {
  languages: Language[];
  total: number;
  items: DashboardItem[];
  /**
   * True when page translation cannot work at all (translation not enabled
   * for canvas_page, or no writable field is translatable). The endpoints
   * refuse with 409 code `translation_not_enabled` and the app hides the
   * whole workspace behind setup instructions. Individually non-translatable
   * fields are normal site configuration: silently excluded, never reported.
   */
  translationBlocked?: boolean;
}

export interface FieldRowData {
  key: string;
  label: string;
  format: string | null;
  source: string;
  target: string;
  /**
   * The source text as it was when this translation was last published, present
   * only on outdated docs and only for fields whose source has since changed.
   */
  sourceWas?: string;
  /**
   * The currently-published target text, present only while a draft is pending
   * and only for fields the draft changes. Lets the review screen diff "what
   * changes on publish" (published → draft); empty means a brand-new string.
   */
  targetWas?: string;
  /** Set when the row's text format is one the user may not use. */
  readOnly?: boolean;
}

export interface FieldGroup {
  component: { uuid: string; label: string } | null;
  label: string;
  rows: FieldRowData[];
}

export interface TranslationDoc {
  /** Present for config docs; absent for content docs. */
  kind?: Kind;
  type?: string;
  id: number | string;
  label: string;
  sourceLangcode: string;
  targetLangcode: string;
  languages: Language[];
  status: Status;
  /** Whether the pending draft has been approved (review pipeline step). */
  approved?: boolean;
  /**
   * Fingerprint of the source's translatable strings when this doc was loaded.
   * Sent back on autosave so the server can detect a concurrent source edit.
   */
  sourceHash?: string;
  /** Whether the optional AI translation submodule is enabled. */
  aiAvailable?: boolean;
  groups: FieldGroup[];
}

/** One row sent to an AI translate endpoint. */
export interface AiTranslateItem {
  key: string;
  format: string | null;
  source: string;
}

export interface SaveResult {
  savedKey: string;
  dataHash: string | null;
  entityType: string;
  entityId: number;
  status: Status;
  /** True when the source changed in Canvas since the editor loaded the doc. */
  sourceChanged?: boolean;
  /** The current source fingerprint, to reset the editor's basis after a save. */
  sourceHash?: string;
}
