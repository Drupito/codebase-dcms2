## 1. Draft store

- [x] 1.1 Add `src/Service/TranslationDraftStore.php` wrapping core `keyvalue`
      (collection `canvas_translate.drafts`), keyed `{subject}:{langcode}`, with
      `get / has / set / delete / allKeys` over a dotted-key → text values map.
      Includes the `UPSTREAM(canvas):` marker.
- [x] 1.2 Register the store + its keyvalue collection in
      `canvas_translate.services.yml`.

## 2. Content path

- [x] 2.1 `ApiTranslateController`: replace `AutoSaveManager` with
      `TranslationDraftStore` in `getTranslation`, `saveDraft`, `discardDraft`,
      `publish` / `publishBatch`, `singleStatus`.
- [x] 2.2 `applyDraftToTarget()`: apply stored text onto the freshly
      `loadUnchanged()` translation via
      `TranslatableFieldGlue::applyValuesToComponents()` + set `title`/`description`
      from the map; do not copy the draft's `components` wholesale.
- [x] 2.3 `PreviewController::preview`: build the target translation in memory from
      the stored values, no auto-save read.
- [x] 2.4 `php -l` the changed PHP files.

## 3. Config path

- [x] 3.1 `ConfigTranslatableGlue`: `save()` → store values; reads overlay stored
      values on the live `LanguageConfigOverride`; `publish()` → write the live
      override directly + record source hash; `hasDraft()`/`discard()` → use the
      store. Dropped `StagedLanguageConfigOverride` + the `AutoSaveManager`
      injection.
- [x] 3.2 Update `canvas_translate.services.yml` `ConfigTranslatableGlue` args.

## 4. Status + cleanup

- [x] 4.1 `TranslationStatus::loadDraftKeys()` → scan the draft store.
- [x] 4.2 `DraftCleanupHooks` → clear the store on page **and** config-entity delete.

## 5. Tests & docs

- [x] 5.1 `CanvasTranslateWorkflowTest::testTranslationDraftIsNotPublishedByCanvas`
      — publishing a page via Canvas's `ApiAutoSaveController::post()` leaves a
      pending French draft unpublished and still in the store.
- [x] 5.2 `CanvasTranslateWorkflowTest::testPublishAppliesDraftOntoCurrentStructure`
      — a structural change after the draft is preserved on publish.
- [x] 5.3 `ConfigTranslateWorkflowTest::testConfigTranslationDraftIsNotPublishedByCanvas`
      — the config draft is not a Canvas staged override; the live override stays
      unwritten.
- [x] 5.4 Existing kernel suites pass against the new store (content 11, config 5).
- [x] 5.5 Update `AGENTS.md` / `README.md`: drafts are workspace-owned, not in
      Canvas auto-save; Canvas publish does not publish translations.

## 6. Verify & archive

- [x] 6.1 `npx -y @fission-ai/openspec@latest validate isolate-translation-drafts-from-canvas-publish --strict` passes.
- [ ] 6.2 Manual smoke on ddev: open a content + a config translation, publish the
      source page and base layout from the Canvas editor, confirm both drafts are
      untouched.
- [ ] 6.3 After it lands, archive the change so `openspec/specs/` reflects it.
