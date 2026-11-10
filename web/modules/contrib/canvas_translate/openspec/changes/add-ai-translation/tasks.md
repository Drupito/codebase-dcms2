# Tasks

## 1. Submodule scaffold
- [x] 1.1 Create `modules/canvas_translate_ai/` with `.info.yml` (deps: `canvas:canvas_translate`, `ai:ai`)
- [x] 1.2 Add `optional_dependencies: [ai]` to parent `canvas_translate.info.yml` (discovery metadata)

## 2. Backend
- [x] 2.1 `src/AiTranslator.php`: `isAvailable()` and `translate(items, sourceLangcode, targetLangcode)` via Drupal AI `translate_text` (default provider/model); skip empty sources; throw typed exception when no provider; `// ponytail: sequential per-string loop`
- [x] 2.2 `src/Controller/AiTranslateController.php`: `translate()` (content) + `translateConfig()` (config); access checks mirroring `ApiTranslateController`; return `{ values }`; 422 on missing provider
- [x] 2.3 `canvas_translate_ai.routing.yml`: POST `.../ai-translate` for content + config; permission `translate canvas content`
- [x] 2.4 `canvas_translate_ai.services.yml`: register `AiTranslator`
- [x] 2.5 Parent `ApiTranslateController::getTranslation()` + `getConfigTranslation()`: add `aiAvailable` (via `module_handler->moduleExists('canvas_translate_ai')`)

## 3. Frontend (`extension/`)
- [x] 3.1 `types.ts`: add `aiAvailable?: boolean` to `TranslationDoc`
- [x] 3.2 `api.ts`: `aiTranslate(...)` + `aiTranslateConfig(...)` → `{ values }`
- [x] 3.3 `components/PageEditor.tsx`: bulk "AI translate" (default empty rows) + "re-translate all"; merge into draft; spinner + error surfacing
- [x] 3.4 `components/FieldRow.tsx`: per-row AI button
- [x] 3.5 `npm run type-check && npm run build`; commit built `dist/index.global.js`

## 4. Tests & specs
- [x] 4.1 Kernel test in `modules/canvas_translate_ai/tests/src/Kernel/` with a mocked `translate_text` provider: key→text map, empty-source skip, 422 when no provider
- [x] 4.2 `npx -y @fission-ai/openspec@latest validate --specs` passes
- [x] 4.3 PHP `php -l`; run kernel tests; manual e2e per proposal prerequisite
