## 1. Backend — per-entity source language

- [x] 1.1 `ApiTranslateController::configItems()`: resolve `$source_langcode`
      from `$entity->language()->getId()` per entity instead of once from the
      language manager, and use it for `sourceLangcode` and the status filter.
- [x] 1.2 `ApiTranslateController::configTranslations()`: same, so completeness
      and outdated are computed against the layout's own source.
- [x] 1.3 Single-config editor payload: report `sourceLangcode` as the entity's
      langcode.
- [x] 1.4 `ApiTranslateController::loadConfigTarget()`: move the "cannot
      translate the source language" rejection after the entity load and compare
      the target against `$entity->language()->getId()`.
- [x] 1.5 `AiTranslateController`: read the source langcode from the loaded
      entity and run `validateTarget()` after the load and access check.
- [x] 1.6 `php -l` the changed PHP files.

## 2. Backend — override-free source strings

- [x] 2.1 `configItems()`: load via `loadMultipleOverrideFree()`, asserting the
      storage is a `ConfigEntityStorageInterface` and skipping any result that
      is not a `ComponentTreeConfigEntityBase`.
- [x] 2.2 `loadConfigTarget()`: load via `loadOverrideFree()`.

## 3. Frontend — dashboard target columns

- [x] 3.1 `Dashboard.tsx`: include the site default language in the target
      columns when any loaded item's `sourceLangcode` differs from it.
- [x] 3.2 Extract that derivation into a pure `extension/columns.ts`
      (`targetLanguages(languages, items)`), and have `Dashboard.tsx`'s `useMemo`
      call it — no behavior change.
- [x] 3.3 `cd extension && npm run type-check && npm run build`; commit the
      rebuilt `extension/dist/index.global.js`.

## 4. Tests

- [x] 4.1 `extension/columns.test.ts`: default language is a column when an item
      is sourced elsewhere; it is not when every item is sourced in it;
      non-default languages are always columns; empty/missing data is safe.
- [x] 4.2 `ConfigTranslateWorkflowTest::testLayoutSourcedInNonDefaultLanguage`:
      a layout whose langcode is not the site default is listed with its own
      `sourceLangcode`, the site default reads as an ordinary target, and the
      translation round-trips into it.
- [x] 4.3 Same test: translating that layout into its own langcode is rejected
      with a client error.
- [x] 4.4 `::testSourceStringsAreReadOverrideFree`: with the config override
      language set to the translated language, the reported source strings stay
      the active configuration and the translation is not falsely outdated.
- [x] 4.5 Run the kernel tests; 34 pass. Both new tests fail against the
      pre-change controller (`sourceLangcode` `en` instead of `fr`; source read
      back as `Bonjour de la région` instead of `Hello from region`).

## 5. Verify & archive

- [x] 5.1 `npx -y @fission-ai/openspec@latest validate --specs` passes.
- [ ] 5.2 Manual smoke on live ddev: a page and a layout authored in a
      non-default language both show a site-default column and translate
      end to end; a default-sourced-only site is unchanged.
- [x] 5.3 Archive the change (`/opsx:archive`) so
      `openspec/specs/` reflects the new behavior.

<!-- cspell:ignore région -->
