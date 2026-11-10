## 1. Page variants in the workspace

- [x] 1.1 `ApiTranslateController::CONFIG_TYPES` as entity type ID strings,
      including `page_variant`; skip types Canvas does not define.
- [x] 1.2 Routing `{type}` requirements accept `page_variant`, including
      `canvas_translate_ai`.
- [x] 1.3 `extension/types.ts`: add `page_variant` to `ConfigType`.
- [x] 1.4 `Dashboard.tsx`: label `page_variant` "Page template"; rebuild
      `extension/dist`.

## 2. Upgrade path

- [x] 2.0 Require Canvas 1.11 in `canvas_translate.info.yml` and
      `composer.json`; drop the guards for a Canvas without page variants.

- [x] 2.1 `PageRegionTranslationUpgrade`: move drafts, approvals, and missing
      overrides by component UUID; idempotent.
- [x] 2.2 `hook_page_variant_insert()` for `theme_<theme>` variants.
- [x] 2.3 `canvas_translate_post_update_0002_move_page_region_translations()`.
- [x] 2.4 Hide `page_region` from the dashboard once
      `canvas.settings:default_page_variant` is set.

## 3. Verification

- [x] 3.1 `PageRegionTranslationUpgradeTest`: drafts, approvals, overrides,
      insert hook, post update, dashboard hiding.
- [x] 3.2 `ConfigTranslateWorkflowTest` still passes (regions without a
      default variant).
- [ ] 3.3 Manual: Canvas 1.10.1 site with translated regions, update Canvas and
      this module, run the database updates; the dashboard lists only the
      "Page template" with fr/de up to date, and the front end renders the
      translations.
- [x] 3.4 `ARCHITECTURE.md` §2.5 documents the upgrade path.
