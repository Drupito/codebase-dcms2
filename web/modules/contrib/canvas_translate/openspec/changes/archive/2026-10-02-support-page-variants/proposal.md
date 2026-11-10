## Why

Canvas 1.11 replaces theme-global page regions with page variants
([#3591806](https://git.drupalcode.org/project/canvas/-/work_items/3591806),
Canvas ADR 0019). Its update hook converts the default theme's enabled regions
into one `theme_<theme>` variant, copies the regions' published language
overrides onto it, and makes it the default variant. From then on regions only
render as a fallback when no page variant resolves, which never happens once a
default variant is set.

Canvas Translate only knows content templates and page regions, so after the
upgrade:

- page variants cannot be translated in the workspace;
- this module's own region state (drafts, approvals, and translations published
  after Canvas's update ran) stays on the regions;
- the dashboard keeps offering the now-dead region translations.

## What Changes

- Page variants become a translatable config layout type, labeled
  "Page template" in the dashboard (matching the Canvas UI).
- Canvas Translate requires Canvas 1.11 (`canvas:canvas (>=1.11.0)`,
  `drupal/canvas: ^1.11.0`), so it cannot be updated ahead of Canvas.
- Layout types the installed Canvas does not provide are skipped, so page
  regions drop out cleanly when Canvas 2.0 removes them.
- `PageRegionTranslationUpgrade` moves region drafts, approvals, and missing
  published translations to the `theme_<theme>` variant, matched by component
  UUID. It runs from `hook_page_variant_insert()` and from
  `canvas_translate_post_update_0002_move_page_region_translations()`.
- Page regions leave the dashboard once `canvas.settings:default_page_variant`
  is set.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `config-translation`: page variants join the workspace, superseded regions
  leave it, and region translation state follows the variant on upgrade. The
  spec's Purpose paragraph is updated on archive to name page variants.

## Impact

- Dependencies: `canvas_translate.info.yml` and `composer.json` require Canvas
  1.11.
- Backend: `src/Controller/ApiTranslateController.php` (config types as entity
  type ID strings, skip missing types, hide superseded regions); new
  `src/Service/PageRegionTranslationUpgrade.php`,
  `src/Hook/PageVariantUpgradeHooks.php`, `canvas_translate.post_update.php`;
  routing accepts `page_variant`.
- Frontend: `extension/types.ts`, `extension/components/Dashboard.tsx`, rebuilt
  `extension/dist`.
- AI submodule: `canvas_translate_ai` routes and controller accept
  `page_variant`.
- Tests: `tests/src/Kernel/PageRegionTranslationUpgradeTest.php`.
- No Canvas core changes. Regions' language overrides are left in place; Canvas
  owns region cleanup and removes the entity type in 2.0.
