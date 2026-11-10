import { chromium } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import http from 'node:http';
import fs from 'node:fs';

// cspell:ignore accomplir Accueil Allez amplifie avec Bienvenue comprend humaine objectifs peuvent traduite équipe

const EXT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../extension');
const OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'qa-out');

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' };
const server = http.createServer((req, res) => {
  const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '') || 'index.html';
  const file = path.join(EXT, rel);
  if (!file.startsWith(EXT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404); res.end('nf'); return;
  }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] ?? 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, r));
const BASE = `http://localhost:${server.address().port}`;

const languages = [
  { id: 'en', name: 'English', direction: 'ltr', isDefault: true },
  { id: 'fr', name: 'French', direction: 'ltr', isDefault: false },
  { id: 'de', name: 'German', direction: 'ltr', isDefault: false },
];

const items = [
  { kind: 'content', type: 'canvas_page', id: 1, label: 'Home', sourceLangcode: 'en', translations: {
    fr: { status: 'draft', hasDraft: true, changed: 1, percent: 60 },
    de: { status: 'up_to_date', hasDraft: false, changed: 1, percent: 100 } } },
  { kind: 'content', type: 'canvas_page', id: 2, label: 'About us', sourceLangcode: 'en', translations: {
    fr: { status: 'outdated', hasDraft: false, changed: 1, percent: 80 },
    de: { status: 'not_translated', hasDraft: false, changed: null, percent: 0 } } },
  { kind: 'content', type: 'canvas_page', id: 3, label: 'Pricing & plans', sourceLangcode: 'en', translations: {
    fr: { status: 'not_translated', hasDraft: false, changed: null, percent: 0 },
    de: { status: 'draft', hasDraft: true, approved: true, changed: 1, percent: 35 } } },
  { kind: 'config', type: 'content_template', id: 'node.article.full', label: 'Article — Full content', sourceLangcode: 'en', translations: {
    fr: { status: 'up_to_date', hasDraft: false, changed: 1, percent: 100 },
    de: { status: 'outdated', hasDraft: false, changed: 1, percent: 90 } } },
  { kind: 'config', type: 'page_region', id: 'olivero.header', label: 'Header region', sourceLangcode: 'en', translations: {
    fr: { status: 'not_translated', hasDraft: false, changed: null, percent: 0 },
    de: { status: 'not_translated', hasDraft: false, changed: null, percent: 0 } } },
];

const doc = {
  id: 1, label: 'Home', sourceLangcode: 'en', targetLangcode: 'fr', languages,
  status: 'draft', approved: false, aiAvailable: true,
  formats: { rich: { toolbar: ['bold', 'italic', 'underline', 'link', '|', 'bulletedList', 'numberedList'], multiBlock: false } },
  groups: [
    { component: null, label: 'Page', rows: [
      // Plain-text change: published "Bienvenue", draft "Accueil" → colorized diff.
      { key: 'title', label: 'Title', format: null, source: 'Home', target: 'Accueil', targetWas: 'Bienvenue' },
      { key: 'meta', label: 'Meta description', format: null, source: '', target: '' },
    ] },
    { component: { uuid: 'u1', label: 'Hero' }, label: 'Hero', rows: [
      // Brand-new string (targetWas '') → reads as all-added in the diff.
      { key: 'h.title', label: 'Title', format: null, source: 'Human expertise that amplifies what your AI agents can do', target: "L'expertise humaine qui amplifie ce que vos agents IA peuvent accomplir.", targetWas: '' },
      // Rich-text change → "Changed" badge, value shown verbatim (no HTML diff).
      { key: 'h.body', label: 'Body', format: 'rich', source: '<p>Build faster with a team that <strong>understands</strong> your goals.</p>', target: '<p>Allez plus vite avec une équipe qui <strong>comprend</strong> vos objectifs.</p>', targetWas: '<p>Allez plus vite avec une équipe.</p>' },
      // Untranslated: no targetWas → hidden under "Changes only", shown when off.
      { key: 'h.cta', label: 'Button label', format: null, source: 'Get started', target: '' },
    ] },
  ],
};

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 2 });

await page.route('**/canvas-translate/api/items?*', (r) =>
  r.fulfill({ json: { languages, total: items.length, items } }));
await page.route('**/canvas-translate/api/items/canvas_page/1/fr', (r) =>
  r.fulfill({ json: doc }));
await page.route('**/session/token', (r) => r.fulfill({ body: 'tok' }));
await page.route('**/canvas-translate/preview/**', (r) =>
  r.fulfill({ contentType: 'text/html', body: '<html><body style="font-family:sans-serif;padding:40px"><h1>Accueil</h1><p>Aperçu de la page traduite.</p></body></html>' }));

const url = `${BASE}/index.html`;
await page.goto(url);
await page.waitForTimeout(700);
await page.screenshot({ path: `${OUT}/1-dashboard.png` });

await page.goto(`${url}#/page/1/fr`);
await page.waitForTimeout(800);
await page.screenshot({ path: `${OUT}/2-editor.png` });

// Open the overflow menu to verify the demoted actions.
const more = page.getByLabel('More actions');
if (await more.count()) { await more.first().click(); await page.waitForTimeout(300); await page.screenshot({ path: `${OUT}/2b-editor-menu.png` }); await page.keyboard.press('Escape'); }

// Open preview pane.
const previewBtn = page.getByText('Show preview', { exact: false });
if (await previewBtn.count()) { await previewBtn.first().click(); await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/3-editor-preview.png` }); }

await page.goto(`${url}#/review`);
await page.waitForTimeout(700);
await page.screenshot({ path: `${OUT}/4-review.png` });

// The per-item review diff screen (changes-only by default).
await page.goto(`${url}#/review/page/1/fr`);
await page.waitForTimeout(800);
await page.screenshot({ path: `${OUT}/5-review-diff.png` });

// Toggle "Changes only" off to show every field, including untranslated.
const changesOnly = page.getByText('Changes only', { exact: false });
if (await changesOnly.count()) { await changesOnly.first().click(); await page.waitForTimeout(300); await page.screenshot({ path: `${OUT}/5b-review-diff-all.png` }); }

// Inline discard confirmation in the queue.
await page.goto(`${url}#/review`);
await page.waitForTimeout(500);
const discardBtn = page.getByRole('button', { name: 'Discard' }).first();
if (await discardBtn.count()) { await discardBtn.click(); await page.waitForTimeout(200); await page.screenshot({ path: `${OUT}/4b-review-discard-confirm.png` }); }

const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
console.log('done; console errors:', errors.length);
await browser.close();
server.close();
