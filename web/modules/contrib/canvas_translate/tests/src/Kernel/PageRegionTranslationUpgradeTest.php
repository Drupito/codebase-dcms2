<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_translate\Controller\ApiTranslateController;
use Drupal\canvas_translate\Service\ApprovalStore;
use Drupal\canvas_translate\Service\ConfigTranslatableGlue;
use Drupal\canvas_translate\Service\PageRegionTranslationUpgrade;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests moving page region translations to the page variant Canvas built.
 *
 * Canvas keeps the component UUIDs when it converts a theme's regions into a
 * "theme_<theme>" variant. Drafts, approvals and published translations are
 * all keyed by those UUIDs, so they can follow the components.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(PageRegionTranslationUpgrade::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class PageRegionTranslationUpgradeTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  private const string UUID_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
  private const string UUID_C = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
  private const string MARKER_VERSION = '3b12c0b99a6caecc';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
    'canvas_translate',
  ];

  /**
   * The draft store.
   */
  private TranslationDraftStore $drafts;

  /**
   * The approval store.
   */
  private ApprovalStore $approvals;

  /**
   * The language manager.
   */
  private ConfigurableLanguageManagerInterface $languageManager;

  /**
   * The service under test.
   */
  private PageRegionTranslationUpgrade $upgrade;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->generateComponentConfig();
    // The marker component ships in config/install, which kernel tests do not
    // import. A variant is only valid with exactly one marker in its tree.
    if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
      Component::create([
        'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'label' => 'Page content',
        'provider' => 'canvas',
        'source' => Marker::SOURCE_PLUGIN_ID,
        'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
        'active_version' => self::MARKER_VERSION,
        'versioned_properties' => [
          'active' => [
            'settings' => [],
            'fallback_metadata' => ['slot_definitions' => []],
          ],
        ],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }
    $this->drafts = $this->container->get(TranslationDraftStore::class);
    $this->approvals = $this->container->get(ApprovalStore::class);
    $language_manager = $this->container->get('language_manager');
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $this->languageManager = $language_manager;
    $this->upgrade = $this->container->get(PageRegionTranslationUpgrade::class);
  }

  /**
   * Drafts, approvals and published translations follow the components.
   *
   * This is the site that updated Canvas first: the variant already exists,
   * and translators kept working on the regions with the old Canvas Translate
   * until this module was updated too.
   */
  public function testUpgradeMovesRegionState(): void {
    // Two regions Canvas would migrate (A, B), and one it would not (C is
    // disabled, so the variant does not contain its component).
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $b = $this->createRegion('header', [self::UUID_B => 'B']);
    $c = $this->createRegion('sidebar_second', [self::UUID_C => 'C'], enabled: FALSE);
    // The variant Canvas built: same components, same UUIDs, plus the marker.
    $variant = $this->createThemeVariant([self::UUID_A => 'A', self::UUID_B => 'B']);

    // Work that happened on the regions after that.
    $this->drafts->set($this->key($a, 'fr'), [
      $this->textKey(self::UUID_A) => 'A fr draft',
      $this->labelKey(self::UUID_A) => 'A label fr',
    ]);
    $this->approvals->approve($this->key($a, 'fr'));
    $this->drafts->set($this->key($b, 'fr'), [$this->textKey(self::UUID_B) => 'B fr draft']);
    $this->drafts->set($this->key($c, 'fr'), [$this->textKey(self::UUID_C) => 'C fr draft']);
    $this->drafts->set($this->key($a, 'de'), [$this->textKey(self::UUID_A) => 'A de draft']);
    $this->approvals->approve($this->key($a, 'de'));

    // Published translations on the regions. B's was published after Canvas
    // migrated, so the variant does not have it; A's is older than what the
    // variant already has.
    $this->publish($a, 'fr', self::UUID_A, 'A fr live (old)');
    $this->publish($b, 'fr', self::UUID_B, 'B fr live');
    $this->publish($c, 'fr', self::UUID_C, 'C fr live');
    $this->publish($variant, 'fr', self::UUID_A, 'A fr variant (newer)');

    $counts = $this->upgrade->upgrade($variant);
    self::assertSame(['drafts' => 4, 'approvals' => 1, 'overrides' => 1], $counts);

    // Drafts: A and B moved to the variant, C stayed on its region.
    self::assertSame($this->sorted([
      $this->textKey(self::UUID_A) => 'A fr draft',
      $this->labelKey(self::UUID_A) => 'A label fr',
      $this->textKey(self::UUID_B) => 'B fr draft',
    ]), $this->sorted($this->drafts->get($this->key($variant, 'fr'))));
    self::assertSame([$this->textKey(self::UUID_A) => 'A de draft'], $this->drafts->get($this->key($variant, 'de')));
    self::assertNull($this->drafts->get($this->key($a, 'fr')));
    self::assertNull($this->drafts->get($this->key($a, 'de')));
    self::assertNull($this->drafts->get($this->key($b, 'fr')));
    self::assertSame([$this->textKey(self::UUID_C) => 'C fr draft'], $this->drafts->get($this->key($c, 'fr')));

    // Approvals: French mixes an approved (A) and an unapproved (B) draft, so
    // the variant is not approved. German only had approved work.
    self::assertFalse($this->approvals->isApproved($this->key($variant, 'fr')));
    self::assertTrue($this->approvals->isApproved($this->key($variant, 'de')));
    self::assertFalse($this->approvals->isApproved($this->key($a, 'fr')));
    self::assertFalse($this->approvals->isApproved($this->key($a, 'de')));

    // Published: B's text was added, A's newer variant text was kept, C and
    // the region overrides themselves were not touched.
    $tree = $this->overrideTree($variant, 'fr');
    self::assertSame('A fr variant (newer)', $tree[self::UUID_A]['inputs']['text']);
    self::assertSame('B fr live', $tree[self::UUID_B]['inputs']['text']);
    self::assertArrayNotHasKey(self::UUID_C, $tree);
    self::assertSame('A fr live (old)', $this->overrideTree($a, 'fr')[self::UUID_A]['inputs']['text']);
    self::assertSame('C fr live', $this->overrideTree($c, 'fr')[self::UUID_C]['inputs']['text']);

    // A second run has nothing left to move.
    self::assertSame(['drafts' => 0, 'approvals' => 0, 'overrides' => 0], $this->upgrade->upgrade($variant));
  }

  /**
   * Creating a theme variant moves the region state right away.
   */
  public function testInsertHook(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $this->drafts->set($this->key($a, 'fr'), [$this->textKey(self::UUID_A) => 'A fr draft']);

    // A variant not built from regions leaves the region alone.
    $custom = $this->createVariant('custom', [self::UUID_A => 'A']);
    self::assertNull($this->drafts->get($this->key($custom, 'fr')));
    self::assertNotNull($this->drafts->get($this->key($a, 'fr')));

    // The variant Canvas builds from the theme's regions takes the draft.
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    self::assertSame([$this->textKey(self::UUID_A) => 'A fr draft'], $this->drafts->get($this->key($variant, 'fr')));
    self::assertNull($this->drafts->get($this->key($a, 'fr')));
  }

  /**
   * The post update gives a variant the UUID the config export has for it.
   *
   * The import that follows then updates the variant instead of recreating
   * it, so it keeps its translations and runs without errors.
   */
  public function testPostUpdateAlignsVariantUuidWithExport(): void {
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    $this->publish($variant, 'fr', self::UUID_A, 'A fr');
    $other = $this->createVariant(PageRegionTranslationUpgrade::VARIANT_ID_PREFIX . 'other', [self::UUID_B => 'B']);
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    // The other site's variant has another UUID.
    $exported = $sync->read($variant->getConfigDependencyName());
    $exported['uuid'] = $this->container->get('uuid')->generate();
    $sync->write($variant->getConfigDependencyName(), $exported);
    // A variant the export does not have is left alone.
    $sync->delete($other->getConfigDependencyName());

    $this->container->get('module_handler')->loadInclude('canvas_translate', 'php', 'canvas_translate.post_update');
    canvas_translate_post_update_0001_align_page_variant_uuids();

    self::assertSame($exported['uuid'], PageVariant::load($variant->id())?->uuid());
    self::assertSame($other->uuid(), PageVariant::load($other->id())?->uuid());
    $importer = $this->configImporter();
    self::assertNotContains($variant->getConfigDependencyName(), $importer->getStorageComparer()->getChangelist('delete'));
    $importer->import();
    self::assertSame([], $importer->getErrors());
    self::assertSame('A fr', $this->overrideTree($variant, 'fr')[self::UUID_A]['inputs']['text']);
  }

  /**
   * The post update covers variants Canvas created before this module knew.
   */
  public function testPostUpdate(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    // The draft arrives after the variant exists, as on a site that updated
    // Canvas first and kept translating regions with the old Canvas Translate.
    $this->drafts->set($this->key($a, 'fr'), [$this->textKey(self::UUID_A) => 'A fr draft']);
    $this->publish($a, 'fr', self::UUID_A, 'A fr live');

    $this->container->get('module_handler')->loadInclude('canvas_translate', 'php', 'canvas_translate.post_update');
    canvas_translate_post_update_0002_move_page_region_translations();

    self::assertSame([$this->textKey(self::UUID_A) => 'A fr draft'], $this->drafts->get($this->key($variant, 'fr')));
    self::assertNull($this->drafts->get($this->key($a, 'fr')));
    self::assertSame('A fr live', $this->overrideTree($variant, 'fr')[self::UUID_A]['inputs']['text']);
  }

  /**
   * Publishing a moved draft keeps the published text of the other regions.
   *
   * A draft replaces the whole published translation, but each region draft
   * only held its own region's text.
   */
  public function testMovedDraftKeepsOtherRegionsPublishedText(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $b = $this->createRegion('header', [self::UUID_B => 'B']);
    $variant = $this->createThemeVariant([self::UUID_A => 'A', self::UUID_B => 'B']);
    // A has a draft that clears its published text; B is only published.
    $this->publish($a, 'fr', self::UUID_A, 'A fr live');
    $this->drafts->set($this->key($a, 'fr'), [$this->labelKey(self::UUID_A) => 'A label fr']);
    $this->publish($b, 'fr', self::UUID_B, 'B fr live');

    $counts = $this->upgrade->upgrade($variant);
    self::assertSame(['drafts' => 1, 'approvals' => 0, 'overrides' => 2], $counts);

    // B's published text joins the draft. A's does not: its draft cleared it.
    self::assertSame($this->sorted([
      $this->labelKey(self::UUID_A) => 'A label fr',
      $this->textKey(self::UUID_B) => 'B fr live',
    ]), $this->sorted($this->drafts->get($this->key($variant, 'fr'))));

    $this->container->get(ConfigTranslatableGlue::class)->publish($variant, 'fr');
    $tree = $this->overrideTree($variant, 'fr');
    self::assertSame('B fr live', $tree[self::UUID_B]['inputs']['text']);
    self::assertSame('A label fr', $tree[self::UUID_A]['label']);
    self::assertArrayNotHasKey('inputs', $tree[self::UUID_A]);
  }

  /**
   * Moving draft entries clears approvals of the drafts that changed.
   *
   * An approval covers a whole draft. A region draft that only partly moves,
   * and a variant draft that gains entries, are no longer what was approved.
   */
  public function testMoveClearsApprovalOfChangedDrafts(): void {
    // One region with a component the variant has (A) and one it has not (C).
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A', self::UUID_C => 'C']);
    $variant = $this->createThemeVariant([self::UUID_B => 'B']);
    // The variant already has an approved draft of its own.
    $this->drafts->set($this->key($variant, 'fr'), [$this->textKey(self::UUID_B) => 'B fr draft']);
    $this->approvals->approve($this->key($variant, 'fr'));
    $this->drafts->set($this->key($a, 'fr'), [
      $this->textKey(self::UUID_A) => 'A fr draft',
      $this->textKey(self::UUID_C) => 'C fr draft',
    ]);
    $this->approvals->approve($this->key($a, 'fr'));
    // Canvas added A to the variant.
    $variant->set('component_tree', [...$variant->get('component_tree'), $this->heading(self::UUID_A, 'A')])->save();

    self::assertSame(['drafts' => 1, 'approvals' => 0, 'overrides' => 0], $this->upgrade->upgrade($variant));
    self::assertSame([$this->textKey(self::UUID_C) => 'C fr draft'], $this->drafts->get($this->key($a, 'fr')));
    self::assertFalse($this->approvals->isApproved($this->key($a, 'fr')));
    self::assertSame($this->sorted([
      $this->textKey(self::UUID_A) => 'A fr draft',
      $this->textKey(self::UUID_B) => 'B fr draft',
    ]), $this->sorted($this->drafts->get($this->key($variant, 'fr'))));
    self::assertFalse($this->approvals->isApproved($this->key($variant, 'fr')));
  }

  /**
   * A variant draft that already had every moved entry keeps its approval.
   */
  public function testMoveKeepsApprovalOfUnchangedVariantDraft(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $variant = $this->createThemeVariant([self::UUID_B => 'B']);
    $this->drafts->set($this->key($variant, 'fr'), [$this->textKey(self::UUID_A) => 'A fr variant draft']);
    $this->approvals->approve($this->key($variant, 'fr'));
    $this->drafts->set($this->key($a, 'fr'), [$this->textKey(self::UUID_A) => 'A fr region draft']);
    $variant->set('component_tree', [...$variant->get('component_tree'), $this->heading(self::UUID_A, 'A')])->save();

    $this->upgrade->upgrade($variant);

    self::assertSame([$this->textKey(self::UUID_A) => 'A fr variant draft'], $this->drafts->get($this->key($variant, 'fr')));
    self::assertTrue($this->approvals->isApproved($this->key($variant, 'fr')));
    self::assertNull($this->drafts->get($this->key($a, 'fr')));
  }

  /**
   * An import that creates the variant leaves its translations as exported.
   *
   * The regions still have translations the exported variant lacks. Merging
   * them after the import made the next import delete them again.
   */
  public function testImportCreatingVariantTwiceMatchesExport(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $this->publish($a, 'fr', self::UUID_A, 'A fr on the region');
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    // The other site's variant has no French translation.
    $sync->createCollection('language.fr')->delete($variant->getConfigDependencyName());
    $variant->delete();

    foreach ([1, 2] as $run) {
      $importer = $this->configImporter();
      $importer->import();
      self::assertSame([], $importer->getErrors(), "Import $run");
      self::assertSame([], $this->overrideTree($variant, 'fr'), "Import $run");
      self::assertFalse($this->configImporter()->getStorageComparer()->hasChanges(), "Active config matches the export after import $run.");
    }
  }

  /**
   * A config import that recreates the variant keeps the moved drafts.
   *
   * Each site creates "theme_<theme>" with its own UUID during the Canvas
   * update, so importing the variant exported from another site deletes and
   * recreates it. Drafts are not config and exist only on this site.
   */
  public function testConfigImportRecreatesVariant(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $this->drafts->set($this->key($a, 'fr'), [$this->textKey(self::UUID_A) => 'A fr draft']);
    $this->approvals->approve($this->key($a, 'fr'));
    $this->publish($a, 'fr', self::UUID_A, 'A fr live');
    // The Canvas update on this site: the hook moves everything over.
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    self::assertNull($this->drafts->get($this->key($a, 'fr')));

    // The import deletes the variant, then creates the other site's copy.
    $variant->setSyncing(TRUE)->delete();
    $variant = $this->createThemeVariant([self::UUID_A => 'A'], syncing: TRUE);

    self::assertSame([$this->textKey(self::UUID_A) => 'A fr draft'], $this->drafts->get($this->key($variant, 'fr')));
    self::assertTrue($this->approvals->isApproved($this->key($variant, 'fr')));
    // The variant's overrides come from the sync directory, not from us.
    self::assertSame([], $this->overrideTree($variant, 'fr'));
  }

  /**
   * A config import leaves the variant translations as exported.
   *
   * The regions keep their translations after the move. A variant component
   * whose strings were all cleared must not get the region text back on the
   * next import, or the active config no longer matches the export.
   */
  public function testImportDoesNotRestoreClearedVariantTranslations(): void {
    $a = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $this->publish($a, 'fr', self::UUID_A, 'A fr on the region');
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    self::assertSame('A fr on the region', $this->overrideTree($variant, 'fr')[self::UUID_A]['inputs']['text']);
    // A translator clears every string of the component on the variant.
    $this->languageManager->getLanguageConfigOverride('fr', $variant->getConfigDependencyName())->delete();
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    // An unrelated change to import.
    $site = $sync->read('system.site');
    $site['name'] = 'Changed elsewhere';
    $sync->write('system.site', $site);

    $this->configImporter()->import();

    self::assertSame([], $this->overrideTree($variant, 'fr'));
    self::assertFalse($this->configImporter()->getStorageComparer()->hasChanges(), 'Active config matches the export after the import.');
  }

  /**
   * A config import clears drafts of what it deletes, not of what it recreates.
   */
  public function testImportClearsDraftsOfDeletedConfig(): void {
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    $other = $this->createVariant('other', [self::UUID_B => 'B']);
    $this->drafts->set($this->key($variant, 'fr'), [$this->textKey(self::UUID_A) => 'A fr draft']);
    $this->drafts->set($this->key($other, 'fr'), [$this->textKey(self::UUID_B) => 'B fr draft']);
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    // The other site's variant has another UUID, so the import recreates it.
    $exported = $sync->read($variant->getConfigDependencyName());
    $exported['uuid'] = $this->container->get('uuid')->generate();
    $sync->write($variant->getConfigDependencyName(), $exported);
    $sync->delete($other->getConfigDependencyName());

    $importer = $this->configImporter();
    $importer->import();
    self::assertSame([], $importer->getErrors());
    self::assertNull(PageVariant::load('other'));
    self::assertNull($this->drafts->get($this->key($other, 'fr')));
    self::assertSame([$this->textKey(self::UUID_A) => 'A fr draft'], $this->drafts->get($this->key($variant, 'fr')));
  }

  /**
   * Regions leave the dashboard once a default page variant replaces them.
   *
   * Canvas renders regions only when no page variant resolves, and its
   * migration sets the converted variant as the default.
   */
  public function testDashboardHidesSupersededRegions(): void {
    $this->setUpCurrentUser(permissions: [PageVariant::ADMIN_PERMISSION, 'translate canvas content']);
    $region = $this->createRegion('sidebar_first', [self::UUID_A => 'A']);
    $variant = $this->createThemeVariant([self::UUID_A => 'A']);
    $controller = ApiTranslateController::create($this->container);
    $listed = function () use ($controller): array {
      $items = \json_decode((string) $controller->items(Request::create('/canvas-translate/api/items'))->getContent(), TRUE)['items'];
      $ids = \array_map(static fn(array $i): string => $i['type'] . ':' . $i['id'], \array_filter($items, static fn(array $i): bool => $i['kind'] === 'config'));
      \sort($ids);
      return $ids;
    };

    // Without a default variant, regions may still render, so both are listed.
    self::assertSame(['page_region:' . $region->id(), 'page_variant:' . $variant->id()], $listed());

    $this->config('canvas.settings')->set('default_page_variant', $variant->id())->save();
    self::assertSame(['page_variant:' . $variant->id()], $listed());
  }

  /**
   * Creates a page region in the default theme with heading components.
   *
   * Goes through the entity type manager: the page region class is deprecated
   * in Canvas 1.11, so we use the more generic ComponentTreeConfigEntityBase
   * to not trigger deprecation warnings in the test.
   *
   * @param string $region
   *   The region name.
   * @param array<string, string> $headings
   *   Heading text keyed by component UUID.
   * @param bool $enabled
   *   Whether the region is enabled.
   */
  private function createRegion(string $region, array $headings, bool $enabled = TRUE): ComponentTreeConfigEntityBase {
    $tree = [];
    foreach ($headings as $uuid => $text) {
      $tree[] = $this->heading($uuid, $text);
    }
    $entity = $this->container->get('entity_type.manager')->getStorage('page_region')->create([
      'theme' => $this->container->get('theme_handler')->getDefault(),
      'region' => $region,
      'component_tree' => $tree,
    ]);
    \assert($entity instanceof ComponentTreeConfigEntityBase);
    if ($enabled) {
      $entity->enable();
    }
    $entity->save();
    return $entity;
  }

  /**
   * Creates the variant Canvas would build from the default theme's regions.
   *
   * @param array<string, string> $headings
   *   Heading text keyed by component UUID.
   * @param bool $syncing
   *   Whether to save it the way a config import does.
   */
  private function createThemeVariant(array $headings, bool $syncing = FALSE): PageVariant {
    $theme = $this->container->get('theme_handler')->getDefault();
    return $this->createVariant(PageRegionTranslationUpgrade::VARIANT_ID_PREFIX . $theme, $headings, $syncing);
  }

  /**
   * Creates a page variant with the given headings and the content marker.
   *
   * @param string $id
   *   The variant ID.
   * @param array<string, string> $headings
   *   Heading text keyed by component UUID.
   * @param bool $syncing
   *   Whether to save it the way a config import does.
   */
  private function createVariant(string $id, array $headings, bool $syncing = FALSE): PageVariant {
    $tree = [
      [
        'uuid' => $this->container->get('uuid')->generate(),
        'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'component_version' => self::MARKER_VERSION,
        'inputs' => [],
      ],
    ];
    foreach ($headings as $uuid => $text) {
      $tree[] = $this->heading($uuid, $text);
    }
    $variant = PageVariant::create(['id' => $id, 'label' => $id, 'component_tree' => $tree]);
    $variant->setSyncing($syncing)->save();
    return $variant;
  }

  /**
   * A heading component instance for a component tree.
   */
  private function heading(string $uuid, string $text): array {
    $component = Component::load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return [
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => $component->getActiveVersion(),
      'inputs' => ['text' => $text, 'element' => 'h3'],
      'label' => "$text label",
    ];
  }

  /**
   * Writes a published translation for one heading's text.
   */
  private function publish(ComponentTreeConfigEntityBase $entity, string $langcode, string $uuid, string $text): void {
    $override = $this->languageManager->getLanguageConfigOverride($langcode, $entity->getConfigDependencyName());
    $tree = $override->get('component_tree') ?? [];
    $tree[$uuid] = ['inputs' => ['text' => $text]];
    $override->set('component_tree', $tree)->save();
  }

  /**
   * The published translation tree of an entity in a language.
   */
  private function overrideTree(ComponentTreeConfigEntityBase $entity, string $langcode): array {
    return $this->languageManager->getLanguageConfigOverride($langcode, $entity->getConfigDependencyName())->get('component_tree') ?? [];
  }

  /**
   * A draft with its keys sorted, so tests do not depend on region order.
   */
  private function sorted(?array $draft): ?array {
    if ($draft !== NULL) {
      \ksort($draft);
    }
    return $draft;
  }

  /**
   * The draft and approval key of an entity in a language.
   */
  private function key(ComponentTreeConfigEntityBase $entity, string $langcode): string {
    return TranslationDraftStore::key($entity->getConfigDependencyName(), $langcode);
  }

  /**
   * The draft key of a heading's text.
   */
  private function textKey(string $uuid): string {
    return "component_tree.$uuid.inputs.text";
  }

  /**
   * The draft key of a component's label.
   */
  private function labelKey(string $uuid): string {
    return "component_tree.$uuid.label";
  }

}
