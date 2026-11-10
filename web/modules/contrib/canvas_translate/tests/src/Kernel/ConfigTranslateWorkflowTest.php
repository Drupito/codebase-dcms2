<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Controller\ApiTranslateController;
use Drupal\canvas_translate\Entity\ConfigBaseline;
use Drupal\canvas_translate\Service\ApprovalStore;
use Drupal\canvas_translate\Service\ConfigTranslatableGlue;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\canvas_translate\Service\TranslationStatus;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// cspell:ignore brouillon modèle région Salut

/**
 * Round-trip test of the Canvas Translate CONFIG-entity workflow.
 *
 * Config translations are language config overrides, not entity translations.
 * This proves: enumerating translatable strings via the glue, saving a French
 * string into the `fr` override, the source (active config) staying unchanged,
 * and status moving not_translated -> up_to_date -> outdated when the source
 * changes.
 *
 * @see \Drupal\Tests\canvas\Functional\TranslationTest::createPageRegionWithFrenchOverride()
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ConfigTranslatableGlue::class)]
#[CoversClass(ApiTranslateController::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ConfigTranslateWorkflowTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_HEADING = '33333333-3333-4333-8333-333333333333';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'content_translation',
    'canvas_translate',
    // For the ContentTemplate config-translation coverage.
    'field',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // The dashboard is a unified list that also queries content pages.
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installConfig(['language', 'field', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: [
      // The page region admin permission, not via the class: page regions are
      // deprecated in Canvas 1.11.
      'administer page template',
      ContentTemplate::ADMIN_PERMISSION,
      'translate canvas content',
    ]);
  }

  /**
   * Proves the config-entity translation round trip end to end.
   */
  public function testConfigTranslationRoundTrip(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $region = $this->createRegion([
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from region', 'element' => 'h3'],
          'label' => 'English region heading',
        ],
      ],
    ]);
    $region->enable()->save();
    $config_name = $region->getConfigDependencyName();

    $id = (string) $region->id();

    // 1. Initially: no override, no draft, status not_translated, 0% complete.
    self::assertFalse($glue->hasOverride($this->loadRegion($region->id()), 'fr'));
    self::assertFalse($glue->hasDraft($this->loadRegion($region->id()), 'fr'));
    self::assertSame(TranslationStatus::NOT_TRANSLATED, $this->configStatus($controller, 'page_region', $id, 'fr'));
    self::assertSame(0, $this->dashboardPercent($controller, 'fr', $id));

    // 2. The field list exposes the source strings with empty targets.
    $rows = $this->rowsFor($controller, 'page_region', $id, 'fr');
    self::assertArrayHasKey('component_tree.' . self::UUID_HEADING . '.inputs.text', $rows);
    self::assertSame('Hello from region', $rows['component_tree.' . self::UUID_HEADING . '.inputs.text']['source']);
    self::assertSame('', $rows['component_tree.' . self::UUID_HEADING . '.inputs.text']['target']);
    self::assertSame('English region heading', $rows['component_tree.' . self::UUID_HEADING . '.label']['source']);

    // 3. Saving a French string STAGES A DRAFT — the live override is
    //    untouched.
    $save = self::decode($controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
      'component_tree.' . self::UUID_HEADING . '.label' => 'Titre de la région',
    ])));
    self::assertSame(TranslationStatus::DRAFT, $save['status']);
    self::assertTrue($glue->hasDraft($this->loadRegion($region->id()), 'fr'));
    self::assertFalse($glue->hasOverride($this->loadRegion($region->id()), 'fr'));
    self::assertTrue($language_manager->getLanguageConfigOverride('fr', $config_name)->isNew());

    // 4. The field list reflects the DRAFT's values; status draft, percent 100.
    $rows = $this->rowsFor($controller, 'page_region', $id, 'fr');
    self::assertSame('Bonjour de la région', $rows['component_tree.' . self::UUID_HEADING . '.inputs.text']['target']);
    self::assertSame('Titre de la région', $rows['component_tree.' . self::UUID_HEADING . '.label']['target']);
    self::assertSame(TranslationStatus::DRAFT, $this->configStatus($controller, 'page_region', $id, 'fr'));
    self::assertSame(100, $this->dashboardPercent($controller, 'fr', $id));

    // 5. Publishing flushes the draft to the live `fr` override, in the
    //    canonical
    // shape (component_tree keyed by UUID, with inputs + label).
    $pub = self::decode($controller->publishConfigTranslation('page_region', $id, 'fr', self::patch([])));
    self::assertSame(TranslationStatus::UP_TO_DATE, $pub['status']);
    self::assertFalse($glue->hasDraft($this->loadRegion($region->id()), 'fr'));
    $override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    self::assertFalse($override->isNew());
    $tree = $override->getRawData()['component_tree'];
    self::assertSame('Bonjour de la région', $tree[self::UUID_HEADING]['inputs']['text']);
    self::assertSame('Titre de la région', $tree[self::UUID_HEADING]['label']);

    // 6. The SOURCE (active config) is unchanged.
    $reloaded = $this->loadRegion($region->id());
    \assert($reloaded instanceof ComponentTreeConfigEntityBase);
    self::assertSame('Hello from region', self::headingText($reloaded));
    self::assertSame('English region heading', $this->headingLabel($reloaded));

    // 7. A new draft over a published override can be discarded, reverting to
    //    the
    // published state with the live override intact.
    self::decode($controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Salut (brouillon)',
      'component_tree.' . self::UUID_HEADING . '.label' => 'Titre de la région',
    ])));
    self::assertSame(TranslationStatus::DRAFT, $this->configStatus($controller, 'page_region', $id, 'fr'));
    self::decode($controller->discardConfigTranslation('page_region', $id, 'fr'));
    self::assertFalse($glue->hasDraft($this->loadRegion($region->id()), 'fr'));
    self::assertSame(TranslationStatus::UP_TO_DATE, $this->configStatus($controller, 'page_region', $id, 'fr'));
    // The published value survived the discard (the draft never reached live).
    self::assertSame('Bonjour de la région', $language_manager->getLanguageConfigOverride('fr', $config_name)->getRawData()['component_tree'][self::UUID_HEADING]['inputs']['text']);

    // 8. Editing the SOURCE translatable string flags the published override
    //    outdated.
    $reloaded = $this->loadRegion($region->id());
    \assert($reloaded instanceof ComponentTreeConfigEntityBase);
    $reloaded->set('component_tree', [
      [
        'uuid' => self::UUID_HEADING,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello again from region', 'element' => 'h3'],
        'label' => 'English region heading',
      ],
    ])->save();
    $reloaded = $this->loadRegion($region->id());
    \assert($reloaded instanceof ComponentTreeConfigEntityBase);
    self::assertTrue($glue->isOutdated($reloaded, 'fr'));
    self::assertSame(TranslationStatus::OUTDATED, $this->configStatus($controller, 'page_region', $id, 'fr'));

    // 9. Discarding with no pending draft removes the published override;
    //    status
    // returns to not_translated.
    self::decode($controller->discardConfigTranslation('page_region', $id, 'fr'));
    self::assertFalse($glue->hasOverride($this->loadRegion($region->id()), 'fr'));
    self::assertSame(TranslationStatus::NOT_TRANSLATED, $this->configStatus($controller, 'page_region', $id, 'fr'));
  }

  /**
   * A draft discarded before publishing leaves no live override behind.
   */
  public function testDiscardDraftBeforePublish(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $region = $this->createRegion([
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from region', 'element' => 'h3'],
          'label' => 'English region heading',
        ],
      ],
    ]);
    $region->enable()->save();
    $id = (string) $region->id();
    $config_name = $region->getConfigDependencyName();

    // Stage a draft, then discard it before it is ever published.
    self::decode($controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
    ])));
    self::assertTrue($glue->hasDraft($this->loadRegion($region->id()), 'fr'));

    self::decode($controller->discardConfigTranslation('page_region', $id, 'fr'));
    self::assertFalse($glue->hasDraft($this->loadRegion($region->id()), 'fr'));
    self::assertFalse($glue->hasOverride($this->loadRegion($region->id()), 'fr'));
    self::assertTrue($language_manager->getLanguageConfigOverride('fr', $config_name)->isNew());
    self::assertSame(TranslationStatus::NOT_TRANSLATED, $this->configStatus($controller, 'page_region', $id, 'fr'));
  }

  /**
   * Tests the CONFIG round trip for the other config type: ContentTemplate.
   *
   * The dashboard declares two translatable config types (page_region and
   * content_template) that share the same glue, but only page_region was
   * covered. This proves a content_template translates through the same
   * controller endpoints: source enumerated, a draft staged and published to
   * the live `fr` override, and the active (source) config left untouched.
   */
  public function testContentTemplateTranslationRoundTrip(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from template', 'element' => 'h2'],
          'label' => 'English template heading',
        ],
      ],
    ]);
    $template->save();
    $id = (string) $template->id();
    $config_name = $template->getConfigDependencyName();
    $key = 'component_tree.' . self::UUID_HEADING . '.inputs.text';

    // 1. Initially not translated, exposing the source string with empty
    //    target.
    self::assertSame(TranslationStatus::NOT_TRANSLATED, $this->configStatus($controller, 'content_template', $id, 'fr'));
    $rows = $this->rowsFor($controller, 'content_template', $id, 'fr');
    self::assertSame('Hello from template', $rows[$key]['source']);
    self::assertSame('', $rows[$key]['target']);

    // 2. Stage a French draft — the live override stays untouched.
    self::decode($controller->saveConfigTranslation('content_template', $id, 'fr', self::patch([
      $key => 'Bonjour du modèle',
      'component_tree.' . self::UUID_HEADING . '.label' => 'Titre du modèle',
    ])));
    self::assertSame(TranslationStatus::DRAFT, $this->configStatus($controller, 'content_template', $id, 'fr'));
    self::assertTrue($language_manager->getLanguageConfigOverride('fr', $config_name)->isNew());

    // 3. Publish flushes the draft to the live `fr` override.
    $pub = self::decode($controller->publishConfigTranslation('content_template', $id, 'fr', self::patch([])));
    self::assertSame(TranslationStatus::UP_TO_DATE, $pub['status']);
    self::assertFalse($glue->hasDraft(ContentTemplate::load($id), 'fr'));
    $override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    self::assertFalse($override->isNew());
    $tree = $override->getRawData()['component_tree'];
    self::assertSame('Bonjour du modèle', $tree[self::UUID_HEADING]['inputs']['text']);
    self::assertSame('Titre du modèle', $tree[self::UUID_HEADING]['label']);

    // 4. The source (active config) is unchanged.
    $reloaded = ContentTemplate::load($id);
    \assert($reloaded instanceof ContentTemplate);
    $item = $reloaded->getComponentTree()->getComponentTreeItemByUuid(self::UUID_HEADING);
    self::assertSame('Hello from template', $item?->getInputs()['text'] ?? NULL);
    self::assertSame('English template heading', $item?->getLabel());
  }

  /**
   * Config staleness lives in an exportable baseline, so it survives a deploy.
   *
   * Config has no `content_translation_outdated` field; staleness is tracked in
   * a `canvas_translate_baseline` CONFIG ENTITY that snapshots the published
   * source strings. Because it is config (not keyvalue), it round-trips through
   * export / import — so after a deploy a changed source is still correctly
   * reported outdated, fixing the old keyvalue blind spot (which was empty
   * post-deploy and falsely reported every override up-to-date).
   */
  public function testOutdatedSurvivesConfigExportImport(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);

    $region = $this->createRegion([
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from region', 'element' => 'h3'],
          'label' => 'English region heading',
        ],
      ],
    ]);
    $region->enable()->save();
    $id = (string) $region->id();
    $config_name = $region->getConfigDependencyName();

    // Publish a translation (records the baseline) then change the source.
    $controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
    ]));
    $controller->publishConfigTranslation('page_region', $id, 'fr', self::patch([]));
    $this->editRegionSource($id);

    // The baseline correctly sees the source change as outdated.
    self::assertTrue($glue->isOutdated($this->loadRegion($id), 'fr'));

    // The baseline is exportable config: simulate a deploy by round-tripping
    // the entity through its own export array (delete, then recreate from the
    // export).
    $storage = $this->container->get('entity_type.manager')->getStorage(ConfigBaseline::ENTITY_TYPE_ID);
    $baseline = $storage->load(ConfigBaseline::makeId('fr', $config_name));
    \assert($baseline instanceof ConfigBaseline);
    $exported = $baseline->toArray();
    self::assertNotEmpty($exported['source']);
    $baseline->delete();
    $storage->create($exported)->save();

    // After the round-trip the override still (correctly) reports outdated —
    // the post-deploy blind spot the keyvalue hash had is gone.
    self::assertTrue($glue->hasOverride($this->loadRegion($id), 'fr'));
    self::assertTrue($glue->isOutdated($this->loadRegion($id), 'fr'));
    self::assertSame(TranslationStatus::OUTDATED, $this->configStatus($controller, 'page_region', $id, 'fr'));
  }

  /**
   * Tests that a changed config source surfaces per-field "Source was…" data.
   *
   * Like content does.
   */
  public function testConfigEmitsSourceWasPerField(): void {
    $controller = ApiTranslateController::create($this->container);

    $region = $this->createTranslatedRegion();
    $id = (string) $region->id();
    $key = 'component_tree.' . self::UUID_HEADING . '.inputs.text';

    $this->editRegionSource($id);

    $rows = $this->rowsFor($controller, 'page_region', $id, 'fr');
    self::assertSame('Hello again from region', $rows[$key]['source']);
    self::assertSame('Hello from region', $rows[$key]['sourceWas']);
    // The unchanged label carries no diff marker.
    self::assertArrayNotHasKey('sourceWas', $rows['component_tree.' . self::UUID_HEADING . '.label']);
  }

  /**
   * The dashboard reports how many config source strings changed.
   */
  public function testConfigChangedStringsInDashboard(): void {
    $controller = ApiTranslateController::create($this->container);

    $region = $this->createTranslatedRegion();
    $id = (string) $region->id();

    // Up to date → no count.
    self::assertNull($this->dashboardChanged($controller, 'fr', $id));

    $this->editRegionSource($id);
    self::assertSame(1, $this->dashboardChanged($controller, 'fr', $id));
  }

  /**
   * Tests that "Mark as reviewed" clears a config override's outdated state.
   */
  public function testConfigMarkReviewedClearsOutdated(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $region = $this->createTranslatedRegion();
    $id = (string) $region->id();
    $config_name = $region->getConfigDependencyName();

    $this->editRegionSource($id);
    self::assertTrue($glue->isOutdated($this->loadRegion($id), 'fr'));

    $res = self::decode($controller->markConfigReviewed('page_region', $id, 'fr'));
    self::assertSame(TranslationStatus::UP_TO_DATE, $res['status']);
    self::assertFalse($glue->isOutdated($this->loadRegion($id), 'fr'));
    // The override value is untouched — mark-reviewed only advances the
    // baseline.
    self::assertSame(
      'Bonjour de la région',
      $language_manager->getLanguageConfigOverride('fr', $config_name)->getRawData()['component_tree'][self::UUID_HEADING]['inputs']['text'],
    );
  }

  /**
   * A config translation draft is invisible to Canvas's publish workflow.
   *
   * The draft lives in this module's own store, not as a Canvas
   * StagedLanguageConfigOverride in the auto-save store, so publishing the base
   * config entity through Canvas (which publishes a config entity's staged
   * overrides) has nothing of ours to publish — the live override stays
   * unwritten.
   */
  public function testConfigTranslationDraftIsNotPublishedByCanvas(): void {
    $glue = $this->container->get(ConfigTranslatableGlue::class);
    $controller = ApiTranslateController::create($this->container);
    $autoSave = $this->container->get(AutoSaveManager::class);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from template', 'element' => 'h2'],
          'label' => 'English template heading',
        ],
      ],
    ]);
    $template->save();
    $id = (string) $template->id();
    $config_name = $template->getConfigDependencyName();

    // Stage a French config translation draft.
    $controller->saveConfigTranslation('content_template', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour du modèle',
    ]));
    self::assertTrue($glue->hasDraft(ContentTemplate::load($id), 'fr'));

    // The draft is NOT a Canvas staged override, so Canvas's config publish
    // (which publishes a config entity's staged overrides) finds nothing of
    // ours.
    $loaded = ContentTemplate::load($id);
    \assert($loaded instanceof ContentTemplate);
    self::assertSame([], $autoSave->groupConfigEntityAutoSaves($loaded));
    // The live `fr` override has not been written either.
    self::assertTrue($language_manager->getLanguageConfigOverride('fr', $config_name)->isNew());
  }

  /**
   * Tests the config draft approval lifecycle.
   *
   * Approve, unapprove, clear-on-edit, clear-on-discard, clear-on-publish, and
   * 400 when there is no draft.
   */
  public function testConfigApprovalLifecycle(): void {
    $controller = ApiTranslateController::create($this->container);
    $approvals = $this->container->get(ApprovalStore::class);

    $region = $this->createRegion([
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from region', 'element' => 'h3'],
          'label' => 'English region heading',
        ],
      ],
    ]);
    $region->enable()->save();
    $id = (string) $region->id();
    $approvalKey = TranslationDraftStore::key($this->loadRegion($id)->getConfigDependencyName(), 'fr');

    // Approving with no draft is a 400 — there is nothing to approve.
    self::assertSame(
      Response::HTTP_BAD_REQUEST,
      $controller->approveConfig('page_region', $id, 'fr')->getStatusCode(),
    );

    // Save a draft → not yet approved.
    $controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
    ]));
    self::assertFalse(self::decode($controller->getConfigTranslation('page_region', $id, 'fr'))['approved']);

    // Approve → reflected on the editor doc and the approval store.
    $res = self::decode($controller->approveConfig('page_region', $id, 'fr'));
    self::assertTrue($res['approved']);
    self::assertTrue(self::decode($controller->getConfigTranslation('page_region', $id, 'fr'))['approved']);
    self::assertTrue($approvals->isApproved($approvalKey));

    // Editing the draft invalidates the approval.
    $controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Salut de la région',
    ]));
    self::assertFalse($approvals->isApproved($approvalKey));

    // Re-approve, then discard → approval cleared with the draft.
    $controller->approveConfig('page_region', $id, 'fr');
    self::assertTrue($approvals->isApproved($approvalKey));
    $controller->discardConfigTranslation('page_region', $id, 'fr');
    self::assertFalse($approvals->isApproved($approvalKey));

    // Re-create a draft, approve it, then unapprove → approval cleared.
    $controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
    ]));
    $controller->approveConfig('page_region', $id, 'fr');
    self::assertTrue($approvals->isApproved($approvalKey));
    $res = self::decode($controller->unapproveConfig('page_region', $id, 'fr'));
    self::assertFalse($res['approved']);
    self::assertFalse($approvals->isApproved($approvalKey));

    // Publish clears the approval: re-approve and publish.
    $controller->approveConfig('page_region', $id, 'fr');
    self::assertTrue($approvals->isApproved($approvalKey));
    $controller->publishConfigTranslation('page_region', $id, 'fr', self::patch([]));
    self::assertFalse($approvals->isApproved($approvalKey));
    // No pending draft → not approved.
    self::assertFalse(self::decode($controller->getConfigTranslation('page_region', $id, 'fr'))['approved']);
  }

  /**
   * A layout authored in a non-default language sources from its own langcode.
   *
   * The site default is not automatically the source: a layout created while
   * another language was default (or authored in one) must report its own
   * langcode as the source, so the site default becomes a translatable target
   * rather than being reported as "source" and locked out of translation.
   */
  public function testLayoutSourcedInNonDefaultLanguage(): void {
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertSame('en', $language_manager->getDefaultLanguage()->getId());

    $region = $this->createRegion([
      'langcode' => 'fr',
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Bonjour de la région', 'element' => 'h3'],
          'label' => 'Titre de la région',
        ],
      ],
    ]);
    $region->enable()->save();
    $id = (string) $region->id();
    self::assertSame('fr', $this->loadRegion($id)->language()->getId());

    // The dashboard reports the layout's own langcode as its source, so the
    // site default is an ordinary (untranslated) target, not the source.
    $item = $this->dashboardItem($controller, $id);
    self::assertSame('fr', $item['sourceLangcode']);
    self::assertSame(TranslationStatus::SOURCE, $item['translations']['fr']['status']);
    self::assertSame(TranslationStatus::NOT_TRANSLATED, $item['translations']['en']['status']);

    // Translating into the site default is served, against the fr source.
    $doc = self::decode($controller->getConfigTranslation('page_region', $id, 'en'));
    self::assertSame('fr', $doc['sourceLangcode']);
    self::assertSame('en', $doc['targetLangcode']);
    $rows = $this->rowsFor($controller, 'page_region', $id, 'en');
    $key = 'component_tree.' . self::UUID_HEADING . '.inputs.text';
    self::assertSame('Bonjour de la région', $rows[$key]['source']);
    self::assertSame('', $rows[$key]['target']);

    // …and it round-trips: draft, publish, source untouched.
    $controller->saveConfigTranslation('page_region', $id, 'en', self::patch([
      $key => 'Hello from the region',
    ]));
    self::assertSame(TranslationStatus::DRAFT, $this->configStatus($controller, 'page_region', $id, 'en'));
    $pub = self::decode($controller->publishConfigTranslation('page_region', $id, 'en', self::patch([])));
    self::assertSame(TranslationStatus::UP_TO_DATE, $pub['status']);
    $reloaded = $this->loadRegion($id);
    \assert($reloaded instanceof ComponentTreeConfigEntityBase);
    self::assertSame('Bonjour de la région', self::headingText($reloaded));

    // Its own source language is the one that cannot be targeted.
    self::assertSame(
      Response::HTTP_BAD_REQUEST,
      $controller->getConfigTranslation('page_region', $id, 'fr')->getStatusCode(),
    );
  }

  /**
   * A saved override never masquerades as the layout's source.
   *
   * Config entities load with language overrides applied for the negotiated
   * language, so reading a translated layout while browsing in that language
   * would hand back the *translation* as the source — inflating completeness
   * and reporting a stale translation as fresh. Source reads are override-free.
   */
  public function testSourceStringsAreReadOverrideFree(): void {
    $controller = ApiTranslateController::create($this->container);
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $region = $this->createTranslatedRegion();
    $id = (string) $region->id();
    $key = 'component_tree.' . self::UUID_HEADING . '.inputs.text';

    // Browse in the language that has the override — the config factory now
    // resolves this entity's config to the French override.
    $language_manager->setConfigOverrideLanguage($language_manager->getLanguage('fr'));

    // The source stays the active configuration, not the override.
    $rows = $this->rowsFor($controller, 'page_region', $id, 'fr');
    self::assertSame('Hello from region', $rows[$key]['source']);
    self::assertSame('Bonjour de la région', $rows[$key]['target']);

    // Completeness and staleness are measured against that same source, so an
    // up-to-date translation is not reported as outdated (or vice versa). Only
    // the heading text is translated, not its label — hence 50%, not 100%.
    $item = $this->dashboardItem($controller, $id);
    self::assertSame('en', $item['sourceLangcode']);
    self::assertSame(TranslationStatus::UP_TO_DATE, $item['translations']['fr']['status']);
    self::assertSame(50, $item['translations']['fr']['percent']);

    // Changing the active source is still seen as a source change.
    $this->editRegionSource($id);
    $item = $this->dashboardItem($controller, $id);
    self::assertSame(TranslationStatus::OUTDATED, $item['translations']['fr']['status']);
    self::assertSame('Hello again from region', $this->rowsFor($controller, 'page_region', $id, 'fr')[$key]['source']);
  }

  /**
   * Returns the whole dashboard row for one config item.
   */
  private function dashboardItem(ApiTranslateController $controller, string $id): array {
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')))['items'];
    foreach ($items as $item) {
      if (($item['kind'] ?? NULL) === 'config' && (string) $item['id'] === $id) {
        return $item;
      }
    }
    self::fail("Config item $id not found in dashboard.");
  }

  /**
   * Creates an unsaved page region.
   *
   * Goes through the entity storage: the page region class is deprecated in
   * Canvas 1.11.
   */
  private function createRegion(array $values): ComponentTreeConfigEntityBase {
    $region = $this->container->get('entity_type.manager')->getStorage('page_region')->create($values);
    \assert($region instanceof ComponentTreeConfigEntityBase);
    return $region;
  }

  /**
   * Loads a page region.
   *
   * Goes through the entity storage: the page region class is deprecated in
   * Canvas 1.11.
   */
  private function loadRegion(string $id): ComponentTreeConfigEntityBase {
    $region = $this->container->get('entity_type.manager')->getStorage('page_region')->load($id);
    \assert($region instanceof ComponentTreeConfigEntityBase);
    return $region;
  }

  /**
   * Creates a region with a published French translation of its heading text.
   */
  private function createTranslatedRegion(): ComponentTreeConfigEntityBase {
    $controller = ApiTranslateController::create($this->container);
    $region = $this->createRegion([
      'theme' => $this->defaultTheme(),
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_HEADING,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello from region', 'element' => 'h3'],
          'label' => 'English region heading',
        ],
      ],
    ]);
    $region->enable()->save();
    $id = (string) $region->id();
    $controller->saveConfigTranslation('page_region', $id, 'fr', self::patch([
      'component_tree.' . self::UUID_HEADING . '.inputs.text' => 'Bonjour de la région',
    ]));
    $controller->publishConfigTranslation('page_region', $id, 'fr', self::patch([]));
    $loaded = $this->loadRegion($region->id());
    \assert($loaded instanceof ComponentTreeConfigEntityBase);
    return $loaded;
  }

  /**
   * Returns the dashboard changed-string count for one config item + language.
   */
  private function dashboardChanged(ApiTranslateController $controller, string $langcode, string $id): ?int {
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')))['items'];
    foreach ($items as $item) {
      if (($item['kind'] ?? NULL) === 'config' && (string) $item['id'] === $id) {
        return $item['translations'][$langcode]['changedStrings'] ?? NULL;
      }
    }
    self::fail("Config item $id not found in dashboard.");
  }

  /**
   * Edits the source heading text of a page region (a new source revision).
   */
  private function editRegionSource(string $id): void {
    $region = $this->loadRegion($id);
    \assert($region instanceof ComponentTreeConfigEntityBase);
    $region->set('component_tree', [
      [
        'uuid' => self::UUID_HEADING,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello again from region', 'element' => 'h3'],
        'label' => 'English region heading',
      ],
    ])->save();
  }

  /**
   * Returns the default theme name (whatever the kernel base installs).
   */
  private function defaultTheme(): string {
    return $this->container->get('theme_handler')->getDefault();
  }

  /**
   * Returns the active version string for the heading SDC component.
   */
  private function getHeadingComponentVersion(): string {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('component')->load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

  /**
   * Returns the heading component instance's `text` input.
   */
  private static function headingText(ComponentTreeConfigEntityBase $region): mixed {
    $item = $region->getComponentTree()->getComponentTreeItemByUuid(self::UUID_HEADING);
    return $item?->getInputs()['text'] ?? NULL;
  }

  /**
   * Returns the heading component instance's label.
   */
  private function headingLabel(ComponentTreeConfigEntityBase $region): ?string {
    return $region->getComponentTree()->getComponentTreeItemByUuid(self::UUID_HEADING)?->getLabel();
  }

  /**
   * Returns the editor rows for one config entity + language, keyed by `key`.
   */
  private function rowsFor(ApiTranslateController $controller, string $type, string $id, string $langcode): array {
    $doc = self::decode($controller->getConfigTranslation($type, $id, $langcode));
    $rows = [];
    foreach ($doc['groups'] as $group) {
      foreach ($group['rows'] as $row) {
        $rows[$row['key']] = $row;
      }
    }
    return $rows;
  }

  /**
   * Returns the single-language status reported by getConfigTranslation().
   */
  private function configStatus(ApiTranslateController $controller, string $type, string $id, string $langcode): string {
    return self::decode($controller->getConfigTranslation($type, $id, $langcode))['status'];
  }

  /**
   * Returns the dashboard completeness percent for one config item + language.
   */
  private function dashboardPercent(ApiTranslateController $controller, string $langcode, string $id): int {
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')))['items'];
    foreach ($items as $item) {
      if (($item['kind'] ?? NULL) === 'config' && (string) $item['id'] === $id) {
        return $item['translations'][$langcode]['percent'];
      }
    }
    self::fail("Config item $id not found in dashboard.");
  }

  /**
   * Builds a PATCH request carrying a `values` payload.
   */
  private static function patch(array $values): Request {
    $request = Request::create('/', 'PATCH', content: (string) \json_encode(['values' => $values]));
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  /**
   * Decodes a JSON response body to an array.
   */
  private static function decode(JsonResponse $response): array {
    return \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
  }

}
