# Design

## Require Canvas 1.11

Canvas Translate requires the Canvas version that introduces page variants.
Supporting an update of this module ahead of Canvas would let translators keep
editing region translations with the new module while Canvas still renders
regions, and those edits would drift from the page template translation Canvas
builds when it is updated later. With the dependency, the only supported order
is Canvas first (or both together), and both triggers below cover it.

Alternative considered: support both orders and reconcile on the Canvas update.
Rejected: two code paths for a one-time upgrade, and a window where region and
page template translations can diverge.

## Matching regions to the variant

Canvas keeps component UUIDs when it builds `theme_<theme>` from the theme's
regions, and every piece of this module's state (draft keys, override keys) is
keyed by UUID. A region belongs to the variant when they share at least one
UUID. Only the entries whose UUID is in the variant move; anything else stays on
the region. This handles regions Canvas did not migrate (other themes, disabled
regions) without special cases.

## Two triggers, one idempotent operation

- `hook_page_variant_insert()` runs when Canvas creates the variant while this
  module is installed: inside Canvas's own post update, or later when Canvas is
  enabled for a theme from the theme settings form.
- `canvas_translate_post_update_0002` covers sites that ran Canvas's update
  before this module's code was updated, and translators who kept working on
  regions in between.

Both call `PageRegionTranslationUpgrade::upgrade()`, which is safe to repeat: a
second run finds nothing left to move.

## UUID-mismatch config import

Each site creates `theme_<theme>` during its own Canvas update, so the variants
share an ID but have different UUIDs. Core treats a UUID mismatch as a new
entity: the import deletes the local variant and creates the imported one
(`StorageComparer::addChangelistUpdate()`). Deleting it deletes all its
language overrides (`LanguageConfigFactoryOverride::onConfigDelete()`), and
core only imports the overrides that differ from the active ones. Matching
translations are lost until the next import, and differing ones fail with
"Update target ... is missing".

`canvas_translate_post_update_0001_align_page_variant_uuids()` prevents the
recreate. It gives each `theme_*` variant the UUID the sync directory has for
it, the way core's config installer takes default config from the sync
directory during an import. `drush deploy` runs `updb` before `cim`, so the
import then updates the variant like any other config. Canvas's post updates
sort before this module's, so a variant Canvas creates in the same `updb` is
aligned too. Sites that already imported have matching UUIDs, and nothing
changes.

Core does not allow changing the UUID of a saved config entity
(`ConfigEntityBase::preSave()`), so the update writes the config directly.
Nothing refers to a page variant by its UUID.

An earlier version stashed the overrides before the delete and restored them
after the import. It was dropped: the import still failed, and restoring
translations the export did not contain left the active config different from
the export.

## Conflict rules

- A draft the variant already has wins over moved region entries.
- The variant's published translation wins over a region's.
- An approval covers a whole draft, so the variant draft is approved only when
  every moved region draft was approved and the variant had no draft of its own.
- The staleness baseline is not copied; the next publish of the variant writes
  it.

## Hiding superseded regions

Canvas renders regions only when no page variant resolves
(`PageVariantSelectorSubscriber`). The resolver falls back to
`canvas.settings:default_page_variant`, and Canvas's migration sets it. So the
dashboard lists `page_region` items only while no default variant is set.

Alternatives considered:

- Hide regions whenever the `page_variant` entity type exists. Rejected: on a
  Canvas 1.11 site whose regions were not migrated and that has no default
  variant, regions still render and still need translating.
- Delete region overrides after moving them. Rejected: Canvas core copied them
  and deliberately left the regions in place until 2.0; deleting core-owned
  config is not this module's call.

The config translation routes still accept `page_region`, so an old bookmark
opens read/write instead of returning an error. Nothing renders that text, so
this is harmless and keeps the change small.
