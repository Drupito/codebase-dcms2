<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;

/**
 * Moves page region translation state to the page variant that replaced them.
 *
 * Canvas 1.11 turns a theme's page regions into one page variant, named
 * "theme_<theme>". Canvas copies the published translations (the language
 * config overrides) but knows nothing about this module's drafts and
 * approvals. It also copies nothing that was published after its own update
 * ran, which happens when Canvas is updated while an older Canvas Translate is
 * still installed.
 *
 * The regions a variant was built from are found by the component UUIDs the
 * two still share. Then, for each language:
 * - published region translations the variant override lacks are added,
 * - region drafts move into the variant draft,
 * - the approval moves along when every region that had a draft was approved.
 *
 * Example: the "header" region had a Hungarian draft for its heading. After
 * the upgrade the same heading, same UUID, sits in variant "theme_olivero",
 * and the Hungarian draft shows up there.
 *
 * Only entries whose UUID is in the variant move. Regions Canvas did not
 * migrate (other themes, disabled regions) keep their drafts and overrides.
 * The staleness baseline is not copied; it is written again on the next
 * publish of the variant.
 */
final class PageRegionTranslationUpgrade {

  /**
   * Canvas names the variant it builds from a theme's regions like this.
   */
  public const string VARIANT_ID_PREFIX = 'theme_';

  public function __construct(
    private readonly TranslationDraftStore $draftStore,
    private readonly ApprovalStore $approvalStore,
    private readonly ConfigTranslatableGlue $configGlue,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LanguageConfigFactoryOverrideInterface $languageConfigOverride,
  ) {}

  /**
   * Whether Canvas built this variant from a theme's page regions.
   */
  public static function isThemeVariant(PageVariant $variant): bool {
    return \str_starts_with((string) $variant->id(), self::VARIANT_ID_PREFIX);
  }

  /**
   * Upgrades every theme variant on the site.
   *
   * @return array<string, array{drafts: int, approvals: int, overrides: int}>
   *   What moved, keyed by variant ID.
   */
  public function upgradeAll(): array {
    $result = [];
    foreach ($this->entityTypeManager->getStorage(PageVariant::ENTITY_TYPE_ID)->loadMultiple() as $variant) {
      \assert($variant instanceof PageVariant);
      if (self::isThemeVariant($variant)) {
        $result[(string) $variant->id()] = $this->upgrade($variant);
      }
    }
    return $result;
  }

  /**
   * Moves the translation state of the regions a variant was built from.
   *
   * Safe to run more than once: a second run finds nothing left to move.
   *
   * @param \Drupal\canvas\Entity\PageVariant $variant
   *   The variant.
   *
   * @return array{drafts: int, approvals: int, overrides: int}
   *   How many draft entries, approvals and override entries moved.
   */
  public function upgrade(PageVariant $variant): array {
    $counts = ['drafts' => 0, 'approvals' => 0, 'overrides' => 0];
    $uuids = self::uuids($variant);
    $regions = [];
    foreach ($this->entityTypeManager->getStorage('page_region')->loadMultiple() as $region) {
      \assert($region instanceof ComponentTreeConfigEntityBase);
      if (\array_intersect_key(self::uuids($region), $uuids) !== []) {
        $regions[] = $region;
      }
    }
    if ($regions === []) {
      return $counts;
    }
    $variant_name = $variant->getConfigDependencyName();
    foreach (\array_keys($this->languageManager->getLanguages()) as $langcode) {
      // Overrides first: a new variant draft is filled from the merged
      // override, see ::moveDrafts().
      $counts['overrides'] += $this->mergeOverrides($regions, $uuids, $variant_name, $langcode);
      $counts['drafts'] += $this->moveDrafts($variant, $regions, $uuids, $langcode, $counts['approvals']);
    }
    return $counts;
  }

  /**
   * Moves region draft entries for the variant's components into its draft.
   *
   * A draft replaces the whole published translation on publish, and each
   * region draft only covers its own region. So a variant draft created here
   * also takes the published text of the regions that had no draft. Without
   * it, publishing the moved draft would delete those translations.
   *
   * @param \Drupal\canvas\Entity\PageVariant $variant
   *   The variant.
   * @param list<\Drupal\canvas\Entity\ComponentTreeConfigEntityBase> $regions
   *   The regions the variant was built from.
   * @param array<string, true> $uuids
   *   The variant's component UUIDs.
   * @param string $langcode
   *   The language.
   * @param int $approvals
   *   Incremented when the approval moves too.
   *
   * @return int
   *   How many draft entries moved.
   */
  private function moveDrafts(PageVariant $variant, array $regions, array $uuids, string $langcode, int &$approvals): int {
    $variant_key = TranslationDraftStore::key($variant->getConfigDependencyName(), $langcode);
    $had_own_draft = $this->draftStore->has($variant_key);
    $moved = [];
    // Components of regions that had a draft. That draft is their complete
    // text: a key missing from it was cleared on purpose.
    $drafted = [];
    $all_approved = TRUE;
    foreach ($regions as $region) {
      $region_key = TranslationDraftStore::key($region->getConfigDependencyName(), $langcode);
      $draft = $this->draftStore->get($region_key);
      if ($draft === NULL) {
        continue;
      }
      $keep = [];
      foreach ($draft as $key => $text) {
        if (isset($uuids[self::uuidOfKey((string) $key)])) {
          $moved[$key] = $text;
        }
        else {
          $keep[$key] = $text;
        }
      }
      if ($keep === $draft) {
        // Nothing of this region's draft is in the variant.
        continue;
      }
      $drafted += \array_intersect_key(self::uuids($region), $uuids);
      $all_approved = $all_approved && $this->approvalStore->isApproved($region_key);
      // An approval covers a whole draft, and this one changed.
      $this->approvalStore->clear($region_key);
      if ($keep === []) {
        $this->draftStore->delete($region_key);
      }
      else {
        $this->draftStore->set($region_key, $keep);
      }
    }
    if ($moved === []) {
      return 0;
    }
    $count = \count($moved);
    if (!$had_own_draft) {
      foreach ($this->configGlue->liveOverrideStringsByKey($variant, $langcode) as $key => $text) {
        if (!isset($drafted[self::uuidOfKey($key)]) && $text !== '') {
          $moved += [$key => $text];
        }
      }
    }
    // A draft the variant already had wins over the moved entries.
    $own = $this->draftStore->get($variant_key) ?? [];
    $this->draftStore->set($variant_key, $own + $moved);
    // An approval covers a whole draft. Keep it only when every moved entry
    // was approved and the variant had no draft of its own. Entries added to
    // a draft the variant had invalidate its approval.
    if ($had_own_draft) {
      if (\array_diff_key($moved, $own) !== []) {
        $this->approvalStore->clear($variant_key);
      }
    }
    elseif ($all_approved) {
      $this->approvalStore->approve($variant_key);
      $approvals++;
    }
    return $count;
  }

  /**
   * Adds published region translations the variant override does not have.
   *
   * Canvas copied these once, when it converted the regions. Anything
   * published to a region after that only exists on the region.
   *
   * @param list<\Drupal\canvas\Entity\ComponentTreeConfigEntityBase> $regions
   *   The regions the variant was built from.
   * @param array<string, true> $uuids
   *   The variant's component UUIDs.
   * @param string $variant_name
   *   The variant's config name.
   * @param string $langcode
   *   The language.
   *
   * @return int
   *   How many component entries were added to the variant override.
   */
  private function mergeOverrides(array $regions, array $uuids, string $variant_name, string $langcode): int {
    $from_regions = [];
    foreach ($regions as $region) {
      $override = $this->languageConfigOverride->getOverride($langcode, $region->getConfigDependencyName());
      if ($override->isNew()) {
        continue;
      }
      $tree = $override->get('component_tree');
      if (\is_array($tree)) {
        $from_regions += \array_intersect_key($tree, $uuids);
      }
    }
    if ($from_regions === []) {
      return 0;
    }
    $override = $this->languageConfigOverride->getOverride($langcode, $variant_name);
    $data = $override->isNew() ? [] : $override->getRawData();
    $data = \is_array($data) ? $data : [];
    $existing = \is_array($data['component_tree'] ?? NULL) ? $data['component_tree'] : [];
    // The variant's own translations win. Only fill in what it lacks.
    $new = \array_diff_key($from_regions, $existing);
    if ($new === []) {
      return 0;
    }
    $data['component_tree'] = $existing + $new;
    $override->setData($data)->save();
    return \count($new);
  }

  /**
   * The component UUIDs in a config entity's tree, as array keys.
   *
   * @return array<string, true>
   *   Keyed by UUID.
   */
  private static function uuids(ComponentTreeConfigEntityBase $entity): array {
    $uuids = [];
    foreach ($entity->getComponentTree() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuids[$item->getUuid()] = TRUE;
    }
    return $uuids;
  }

  /**
   * The UUID in a draft key like "component_tree.<uuid>.inputs.text".
   */
  private static function uuidOfKey(string $key): string {
    if (!\str_starts_with($key, 'component_tree.')) {
      return '';
    }
    return \explode('.', $key)[1] ?? '';
  }

}
