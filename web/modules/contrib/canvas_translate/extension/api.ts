import type {
  AiTranslateItem,
  ConfigType,
  DashboardResponse,
  SaveResult,
  Status,
  TranslationDoc,
} from './types.ts';

/**
 * Error carrying HTTP status and server-supplied detail, so callers can branch
 * on status (e.g. 409 publish conflict) and surface a human-readable message.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly details: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Drupal base path. The extension iframe is a static document with no
 * drupalSettings, so this falls back to '/' (matches Canvas Forms; subdirectory
 * installs are not yet supported for page extensions).
 */
export function basePath(): string {
  const baseUrl = (window as { drupalSettings?: { path?: { baseUrl?: string } } })
    .drupalSettings?.path?.baseUrl;
  return baseUrl ?? '/';
}

function apiUrl(path: string): string {
  return `${basePath()}${path}`;
}

let csrfTokenPromise: Promise<string> | null = null;

function getCsrfToken(): Promise<string> {
  if (csrfTokenPromise === null) {
    csrfTokenPromise = fetch(apiUrl('session/token'), { credentials: 'same-origin' })
      .then((res) => {
        if (!res.ok) throw new Error(`Failed to fetch CSRF token: ${res.status}`);
        return res.text();
      })
      .catch((err) => {
        csrfTokenPromise = null;
        throw err;
      });
  }
  return csrfTokenPromise;
}

/**
 * A friendly fallback message for a status code when the server sent no detail.
 */
function genericMessage(status: number): string {
  if (status === 403) return 'You don’t have permission to do that.';
  if (status === 404) return 'That item could no longer be found. It may have been deleted.';
  if (status === 409) return 'Someone else changed this in the meantime. Reload to see the latest.';
  if (status >= 500) return 'Something went wrong on our end. Please try again in a moment.';
  return 'Something went wrong. Please try again.';
}

async function readError(res: Response): Promise<ApiError> {
  const body = (await res.json().catch(() => ({}))) as { error?: string; errors?: Array<{ detail?: string }> };
  const detail =
    body.error ??
    body.errors?.map((e) => e.detail).filter(Boolean).join(' ') ??
    genericMessage(res.status);
  return new ApiError(detail, res.status, body);
}

/** Stable per-session client id, used for Canvas auto-save conflict tracking. */
export const clientInstanceId: string = crypto.randomUUID();

export async function fetchItems(params: {
  search?: string;
  langcode?: string;
  status?: string;
  /** A single page's id: only that page, no config items (per-page overview). */
  id?: number;
} = {}): Promise<DashboardResponse> {
  const qs = new URLSearchParams();
  if (params.search) qs.set('search', params.search);
  if (params.langcode) qs.set('langcode', params.langcode);
  if (params.status) qs.set('status', params.status);
  if (params.id) qs.set('id', String(params.id));
  const res = await fetch(apiUrl(`canvas-translate/api/items?${qs}`), { credentials: 'same-origin' });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as DashboardResponse;
}

export async function fetchTranslation(id: number, langcode: string): Promise<TranslationDoc> {
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}`),
    { credentials: 'same-origin' },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as TranslationDoc;
}

export async function saveDraft(
  id: number,
  langcode: string,
  values: Record<string, string>,
  baseSourceHash?: string,
): Promise<SaveResult> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}`),
    {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ values, clientInstanceId, baseSourceHash }),
    },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as SaveResult;
}

/** Clears a translation's outdated flag without rewriting its values. */
export async function markReviewed(id: number, langcode: string): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}/mark-reviewed`),
    {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken },
    },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

/** Marks a pending draft approved (review pipeline step before publishing). */
export async function approve(id: number, langcode: string): Promise<{ approved: boolean; status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}/approve`),
    { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken } },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { approved: boolean; status: Status };
}

/** Withdraws approval for a pending draft. */
export async function unapprove(id: number, langcode: string): Promise<{ approved: boolean; status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}/unapprove`),
    { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken } },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { approved: boolean; status: Status };
}

export async function discardDraft(id: number, langcode: string): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}`),
    {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken },
    },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

/**
 * Publish a translation draft: writes the translated fields onto the target
 * translation as a new revision, leaving the source and other translations
 * untouched, and clears the draft.
 */
export async function publish(id: number, langcode: string): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}/publish`),
    {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken },
    },
  );
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

/** One published item in a batch publish result. */
export interface BatchPublishItem {
  id: number;
  langcode: string;
  status: Status;
}

/**
 * Publish a reviewed set of page+language drafts in one all-or-nothing batch.
 * Mirrors Canvas's "publish all": if any draft is invalid, none are published.
 */
export async function publishBatch(
  items: Array<{ id: number; langcode: string }>,
): Promise<{ published: number; items: BatchPublishItem[] }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(apiUrl('canvas-translate/api/publish-batch'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ items }),
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { published: number; items: BatchPublishItem[] };
}

// --- Config-entity (Content Template / Page Region) translation ---
//
// Config translations are language config overrides, not entity translations,
// but they have the same draft → publish flow as content: a save stages a draft
// (the live override is untouched) and publish writes it. The endpoints mirror
// the content ones so the editor and Review queue can be reused.

function configUrl(type: ConfigType, id: string, langcode: string, suffix = ''): string {
  return apiUrl(
    `canvas-translate/api/config/${type}/${encodeURIComponent(id)}/${encodeURIComponent(langcode)}${suffix}`,
  );
}

export async function fetchConfigTranslation(
  type: ConfigType,
  id: string,
  langcode: string,
): Promise<TranslationDoc> {
  const res = await fetch(configUrl(type, id, langcode), { credentials: 'same-origin' });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as TranslationDoc;
}

export async function saveConfigTranslation(
  type: ConfigType,
  id: string,
  langcode: string,
  values: Record<string, string>,
  baseSourceHash?: string,
): Promise<{ status: Status; sourceChanged?: boolean; sourceHash?: string }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode), {
    method: 'PATCH',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ values, clientInstanceId, baseSourceHash }),
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status; sourceChanged?: boolean; sourceHash?: string };
}

/** Clears a config override's outdated flag without rewriting it. */
export async function markConfigReviewed(
  type: ConfigType,
  id: string,
  langcode: string,
): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode, '/mark-reviewed'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

/** Marks a pending config translation draft approved. */
export async function approveConfig(
  type: ConfigType,
  id: string,
  langcode: string,
): Promise<{ approved: boolean; status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode, '/approve'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { approved: boolean; status: Status };
}

/** Withdraws approval for a pending config translation draft. */
export async function unapproveConfig(
  type: ConfigType,
  id: string,
  langcode: string,
): Promise<{ approved: boolean; status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode, '/unapprove'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { approved: boolean; status: Status };
}

export async function discardConfigTranslation(
  type: ConfigType,
  id: string,
  langcode: string,
): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode), {
    method: 'DELETE',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

export async function publishConfigTranslation(
  type: ConfigType,
  id: string,
  langcode: string,
  values: Record<string, string>,
): Promise<{ status: Status }> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode, '/publish'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ values, clientInstanceId }),
  });
  if (!res.ok) throw await readError(res);
  return (await res.json()) as { status: Status };
}

// --- Optional AI machine translation (canvas_translate_ai submodule) ---
//
// These POST the source strings the editor already holds and return suggested
// target values keyed by row key. The result is applied to the draft like any
// manual edit — never published directly. Available only when doc.aiAvailable.

export async function aiTranslate(
  id: number,
  langcode: string,
  items: AiTranslateItem[],
): Promise<Record<string, string>> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(
    apiUrl(`canvas-translate/api/items/canvas_page/${id}/${encodeURIComponent(langcode)}/ai-translate`),
    {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ items }),
    },
  );
  if (!res.ok) throw await readError(res);
  return ((await res.json()) as { values: Record<string, string> }).values;
}

export async function aiTranslateConfig(
  type: ConfigType,
  id: string,
  langcode: string,
  items: AiTranslateItem[],
): Promise<Record<string, string>> {
  const csrfToken = await getCsrfToken();
  const res = await fetch(configUrl(type, id, langcode, '/ai-translate'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ items }),
  });
  if (!res.ok) throw await readError(res);
  return ((await res.json()) as { values: Record<string, string> }).values;
}
