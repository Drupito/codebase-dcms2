import { basePath } from './api.ts';

/**
 * Loads Drupal core's text editors into the extension.
 *
 * The extension is a static page, so nothing attaches the libraries a text
 * format's editor needs. The editor-assets endpoint returns exactly what core
 * would attach to a form using those formats; this loads it the way a Drupal
 * page would, so rich text is edited in core's CKEditor 5 with the format's
 * own plugins, toolbar and allowed markup — the same editor Canvas uses.
 *
 * @see \Drupal\canvas_translate\Controller\EditorAssetsController
 */

/** A text format's editor settings, as core puts them in drupalSettings. */
export interface DrupalEditorFormat {
  format: string;
  editor: string;
  editorSettings: Record<string, unknown>;
}

/** The parts of a CKEditor 5 instance the extension uses. */
export interface CKEditor5Instance {
  getData(): string;
  setData(data: string): void;
  data: {
    parse(data: string): unknown;
    stringify(fragment: unknown): string;
  };
  model: {
    document: {
      on(event: 'change:data', callback: () => void): void;
      off(event: 'change:data', callback: () => void): void;
    };
  };
}

/** Core's public JS editor API, as set up by editor.js and ckeditor5.js. */
export interface DrupalGlobal {
  editors: {
    ckeditor5: {
      attach(element: HTMLElement, format: DrupalEditorFormat): void;
      detach(element: HTMLElement, format: DrupalEditorFormat, trigger: string): Promise<void> | void;
    };
  };
  CKEditor5Instances: Map<string, CKEditor5Instance>;
}

interface DrupalSettings {
  ajaxPageState?: { libraries?: string };
  editor?: { formats?: Record<string, DrupalEditorFormat> };
  [key: string]: unknown;
}

declare global {
  interface Window {
    Drupal?: DrupalGlobal;
    drupalSettings?: DrupalSettings;
  }
}

interface EditorAssets {
  settings: DrupalSettings;
  js: Array<Record<string, string | boolean>>;
  css: Array<Record<string, string | boolean>>;
  unavailable: string[];
}

/** One promise per format: its editor settings once loaded, or null. */
const formats = new Map<string, Promise<DrupalEditorFormat | null>>();

/**
 * Asset batches load one after another: each request tells the server which
 * libraries are already on the page, which is only known once the previous
 * batch finished.
 */
let queue: Promise<unknown> = Promise.resolve();

/**
 * Loads the editors for several formats in one request.
 *
 * Call it with every format a page uses before its fields mount, so they share
 * one request instead of one each.
 */
export function preloadEditorFormats(names: string[]): void {
  const missing = [...new Set(names)].filter((name) => !formats.has(name));
  if (missing.length === 0) {
    return;
  }
  const batch = queue.then(() => loadBatch(missing));
  queue = batch.catch(() => undefined);
  for (const name of missing) {
    formats.set(
      name,
      batch.then(
        () => window.drupalSettings?.editor?.formats?.[name] ?? null,
        // A failed load leaves the field to the HTML fallback.
        () => null,
      ),
    );
  }
}

/**
 * Resolves to core's editor settings for a format once its editor is loaded,
 * or null when the format has no CKEditor 5 editor or it failed to load.
 */
export function editorFormat(name: string): Promise<DrupalEditorFormat | null> {
  preloadEditorFormats([name]);
  return formats.get(name)!;
}

/** The global Drupal object, once core's editor scripts have loaded. */
export function drupal(): DrupalGlobal {
  if (!window.Drupal) {
    throw new Error('Drupal core scripts are not loaded.');
  }
  return window.Drupal;
}

async function loadBatch(names: string[]): Promise<void> {
  const params = new URLSearchParams({ formats: names.join(',') });
  const loaded = window.drupalSettings?.ajaxPageState?.libraries;
  if (loaded) {
    params.set('ajax_page_state[libraries]', loaded);
  }
  const res = await fetch(`${basePath()}canvas-translate/api/editor-assets?${params}`, {
    credentials: 'same-origin',
  });
  if (!res.ok) {
    throw new Error(`Failed to load editor assets: ${res.status}`);
  }
  const assets = (await res.json()) as EditorAssets;
  // A format whose libraries are all loaded already still brings its own
  // editor settings, so only a batch with no available format is skipped.
  if (Object.keys(assets.settings).length === 0) {
    return;
  }
  applySettings(assets.settings);
  for (const attributes of assets.css) {
    document.head.append(element('link', attributes));
  }
  for (const attributes of assets.js) {
    await loadScript(attributes);
  }
}

/**
 * Hands settings to core the way a Drupal page does: on first load through the
 * JSON element drupalSettingsLoader.js reads, afterwards merged into the live
 * object as core's AJAX settings command does.
 */
function applySettings(settings: DrupalSettings): void {
  if (window.drupalSettings) {
    deepMerge(window.drupalSettings, settings);
    return;
  }
  const json = document.createElement('script');
  json.type = 'application/json';
  json.dataset.drupalSelector = 'drupal-settings-json';
  json.textContent = JSON.stringify(settings);
  document.body.append(json);
}

function deepMerge(target: Record<string, unknown>, source: Record<string, unknown>): void {
  for (const [key, value] of Object.entries(source)) {
    const current = target[key];
    if (isPlainObject(value) && isPlainObject(current)) {
      deepMerge(current, value);
    } else {
      target[key] = value;
    }
  }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function element(tag: 'link' | 'script', attributes: Record<string, string | boolean>): HTMLElement {
  const el = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (value === true) el.setAttribute(name, '');
    else if (value !== false) el.setAttribute(name, value);
  }
  return el;
}

/** Appends a script and waits for it, so scripts run in the order given. */
function loadScript(attributes: Record<string, string | boolean>): Promise<void> {
  return new Promise((resolve, reject) => {
    const script = element('script', attributes) as HTMLScriptElement;
    script.async = false;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Failed to load ${script.src}`));
    document.body.append(script);
  });
}
