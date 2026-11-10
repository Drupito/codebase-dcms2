<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_translate\Controller\ApiLayoutControllerDecorator;
use Drupal\canvas_translate\Controller\ApiTranslateController;
use Drupal\canvas_translate\Controller\PreviewController;
use Drupal\canvas_translate\Service\ApprovalStore;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\canvas_translate\Service\TranslationStatus;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// cspell:ignore Brouillon française Salut Titel

/**
 * End-to-end test of the Canvas Translate content workflow.
 *
 * Exercises the real services and controllers: status reporting, saving a
 * target draft into this module's own draft store (NOT Canvas's auto-save
 * store, so Canvas's "publish all" never publishes a translation), publishing
 * it onto the current stored structure, and auto-flagging translations outdated
 * when the source changes.
 *
 * @see \Drupal\canvas\Kernel\ApiAutoSaveControllerTranslationTest
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiTranslateController::class)]
#[CoversClass(TranslationStatus::class)]
#[CoversClass(ApiLayoutControllerDecorator::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class CanvasTranslateWorkflowTest extends CanvasKernelTestBase {

  use RequestTrait;
  use AutoSaveRequestTestTrait;
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = '11111111-1111-4111-8111-111111111111';
  private const string UUID_B = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    // Ships the `untranslatable-prop-shapes` SDC (date/email/integer/boolean
    // props), used to prove non-text prop shapes are never offered for
    // translation.
    'canvas_test_translation',
    'language',
    'content_translation',
    'canvas_translate',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: [
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
      'translate any entity',
      'translate canvas content',
    ]);
  }

  /**
   * The full content translation workflow: save, publish, outdated, cleanup.
   */
  public function testFullTranslationWorkflow(): void {
    $controller = ApiTranslateController::create($this->container);
    $status = $this->container->get(TranslationStatus::class);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // 1. Initially the French translation does not exist and is 0% complete.
    self::assertSame(
      TranslationStatus::NOT_TRANSLATED,
      $status->forEntity($page, $status->loadDraftKeys())['fr']['status'],
    );
    self::assertSame(0, $this->dashboardState($controller, $id, 'fr')['percent']);

    // 2. The field list exposes the source strings with empty targets.
    $doc = self::decode($controller->getTranslation($page, 'fr'));
    $rows = self::allRows($doc);
    self::assertSame('Hello', $rows['components.0.text']['source']);
    self::assertSame('', $rows['components.0.text']['target']);
    self::assertSame('English title', $rows['title']['source']);

    // 3. Save a French draft.
    $save = self::decode($controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre français',
      'components.0.text' => 'Bonjour',
    ])));
    self::assertNotNull($save['dataHash']);
    self::assertSame('draft', $save['status']);
    self::assertSame(
      TranslationStatus::DRAFT,
      $status->forEntity($storage->loadUnchanged($id), $status->loadDraftKeys())['fr']['status'],
    );

    // 4. The field list now reflects the draft target values.
    $doc = self::decode($controller->getTranslation($page, 'fr'));
    $rows = self::allRows($doc);
    self::assertSame('Bonjour', $rows['components.0.text']['target']);
    self::assertSame('Titre français', $rows['title']['target']);

    // 5. Publish the draft.
    self::assertTrue($draftStore->has($save['savedKey']));
    $published_status = self::decode($controller->publish($storage->loadUnchanged($id), 'fr'));
    self::assertSame(TranslationStatus::UP_TO_DATE, $published_status['status']);
    // Publishing clears the draft.
    self::assertFalse($draftStore->has($save['savedKey']));

    // 6. The French translation is published; English is untouched.
    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertTrue($published->hasTranslation('fr'));
    self::assertSame('Titre français', $published->getTranslation('fr')->label());
    self::assertSame('Bonjour', self::headingText($published->getTranslation('fr')));
    self::assertSame('English title', $published->label());
    self::assertSame('Hello', self::headingText($published));
    self::assertSame(
      TranslationStatus::UP_TO_DATE,
      $status->forEntity($published, $status->loadDraftKeys())['fr']['status'],
    );
    // The published translation records the real source langcode ('en'), not
    // the copied `und` default. Otherwise the translation overview shows an
    // unknown source and a later save throws FieldTranslationSynchronizer.
    self::assertSame('en', $published->getTranslation('fr')->get('content_translation_source')->value);

    // 7. Editing the source's translatable content flags French outdated.
    $published->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)
      ->setInput(['text' => 'Hello again', 'element' => 'h1']);
    $published->save();
    self::assertSame(
      TranslationStatus::OUTDATED,
      $status->forEntity($storage->loadUnchanged($id), $status->loadDraftKeys())['fr']['status'],
    );

    // 8. The published French translation reports 100% completeness (title +
    // heading text both translated).
    self::assertSame(100, $this->dashboardState($controller, $id, 'fr')['percent']);

    // 9. Deleting the page clears any lingering translation draft, so no
    // orphaned draft remains.
    $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch(['title' => 'Brouillon']));
    self::assertTrue($draftStore->has("canvas_page:$id:fr"));
    $storage->loadUnchanged($id)->delete();
    self::assertFalse($draftStore->has("canvas_page:$id:fr"));
  }

  /**
   * Outdated docs expose, per field, the source as it was at last publish.
   *
   * After a translation is published, changing only one source field must mark
   * the translation outdated and surface `sourceWas` (the prior source text) on
   * exactly that field — derived live from revision history, never stored. The
   * unchanged field carries no diff marker.
   */
  public function testOutdatedShowsChangedSourcePerField(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Translate + publish French.
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $controller->publish($storage->loadUnchanged($id), 'fr');

    // Edit only the heading source; the title is unchanged. Mimic the Canvas
    // editor publishing the source as a new revision (see
    // ApiAutoSaveController).
    $source = $storage->loadUnchanged($id);
    \assert($source instanceof Page);
    $source->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)
      ->setInput(['text' => 'Hello again', 'element' => 'h1']);
    $source->setNewRevision(TRUE);
    $source->save();

    $doc = self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'));
    self::assertSame(TranslationStatus::OUTDATED, $doc['status']);
    $rows = self::allRows($doc);
    // The changed heading carries the previous source text.
    self::assertSame('Hello again', $rows['components.0.text']['source']);
    self::assertSame('Hello', $rows['components.0.text']['sourceWas']);
    // The unchanged title has no diff marker.
    self::assertArrayNotHasKey('sourceWas', $rows['title']);
  }

  /**
   * The review screen sees the published target, so it can diff what publishes.
   *
   * `targetWas` is the currently-published target value, emitted per field only
   * while a draft is pending and only when the draft changes that field:
   *   - no draft           → never present (nothing to diff against)
   *   - brand-new draft    → '' (the string is all-added)
   *   - draft changes field → the prior published value
   *   - draft leaves field  → absent (unchanged on publish)
   */
  public function testReviewExposesPublishedTargetForChangedFields(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // No draft → no targetWas anywhere (nothing to diff against).
    $rows = self::allRows(self::decode($controller->getTranslation($page, 'fr')));
    self::assertArrayNotHasKey('targetWas', $rows['title']);
    self::assertArrayNotHasKey('targetWas', $rows['components.0.text']);

    // Brand-new draft → every changed field is all-added (targetWas === '').
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $rows = self::allRows(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr')));
    self::assertSame('', $rows['title']['targetWas']);
    self::assertSame('Titre', $rows['title']['target']);
    self::assertSame('', $rows['components.0.text']['targetWas']);

    // Publish, then with no pending draft there is again nothing to diff.
    $controller->publish($storage->loadUnchanged($id), 'fr');
    $rows = self::allRows(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr')));
    self::assertArrayNotHasKey('targetWas', $rows['title']);
    self::assertArrayNotHasKey('targetWas', $rows['components.0.text']);

    // A new draft that changes ONLY the heading: the heading carries its prior
    // published value; the unchanged title carries no diff marker.
    $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Salut',
    ]));
    $rows = self::allRows(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr')));
    self::assertSame('Bonjour', $rows['components.0.text']['targetWas']);
    self::assertSame('Salut', $rows['components.0.text']['target']);
    self::assertArrayNotHasKey('targetWas', $rows['title']);
  }

  /**
   * Approval is per-draft review state, cleared whenever the draft moves on.
   *
   * A reviewer approves a pending draft; the flag surfaces on the editor doc
   * and the dashboard. Editing, discarding, or publishing the draft clears it,
   * so an approval always refers to the exact draft that was reviewed.
   * Approving with no draft is rejected.
   */
  public function testApprovalLifecycle(): void {
    $controller = ApiTranslateController::create($this->container);
    $approvals = $this->container->get(ApprovalStore::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Approving with no draft is a 400 — there is nothing to approve.
    self::assertSame(
      Response::HTTP_BAD_REQUEST,
      $controller->approve($storage->loadUnchanged($id), 'fr')->getStatusCode(),
    );

    // Save a draft → not yet approved.
    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));
    self::assertFalse(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'))['approved']);
    self::assertFalse($this->dashboardState($controller, $id, 'fr')['approved']);

    // Approve → reflected on the doc, the dashboard, and the store.
    self::assertTrue(self::decode($controller->approve($storage->loadUnchanged($id), 'fr'))['approved']);
    self::assertTrue(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'))['approved']);
    self::assertTrue($this->dashboardState($controller, $id, 'fr')['approved']);
    self::assertTrue($approvals->isApproved("canvas_page:$id:fr"));

    // Editing the draft invalidates the approval.
    $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch([
      'title' => 'Titre 2',
      'components.0.text' => 'Bonjour',
    ]));
    self::assertFalse($approvals->isApproved("canvas_page:$id:fr"));

    // Re-approve, then discard → approval cleared with the draft.
    $controller->approve($storage->loadUnchanged($id), 'fr');
    $controller->discardDraft($storage->loadUnchanged($id), 'fr');
    self::assertFalse($approvals->isApproved("canvas_page:$id:fr"));

    // Re-create + approve, then publish → approval cleared on publish.
    $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $controller->approve($storage->loadUnchanged($id), 'fr');
    self::assertTrue($approvals->isApproved("canvas_page:$id:fr"));
    $controller->publish($storage->loadUnchanged($id), 'fr');
    self::assertFalse($approvals->isApproved("canvas_page:$id:fr"));
    // With no pending draft, the published doc reports not-approved.
    self::assertFalse(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'))['approved']);
  }

  /**
   * Tests that "Mark as reviewed" clears the outdated flag, not the values.
   *
   * The translator judged the existing translation still valid for the changed
   * source: the flag flips to FALSE on a new revision, but every translated
   * value is preserved exactly.
   */
  public function testMarkReviewedClearsOutdatedWithoutRewrite(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Translate + publish a real French translation (values differ from
    // source).
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $controller->publish($storage->loadUnchanged($id), 'fr');

    // Edit the source → the French translation is flagged outdated.
    $source = $storage->loadUnchanged($id);
    \assert($source instanceof Page);
    $source->set('title', 'English title (edited)');
    $source->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)
      ->setInput(['text' => 'Hello again', 'element' => 'h1']);
    $source->setNewRevision(TRUE);
    $source->save();
    self::assertSame(TranslationStatus::OUTDATED, $this->dashboardState($controller, $id, 'fr')['status']);
    $before = $this->revisionCount($storage, $id);

    // Mark reviewed: the existing translation is accepted as-is.
    $res = self::decode($controller->markReviewed($storage->loadUnchanged($id), 'fr'));
    self::assertSame(TranslationStatus::UP_TO_DATE, $res['status']);

    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    $fr = $published->getTranslation('fr');
    \assert($fr instanceof Page);
    // Values are untouched.
    self::assertSame('Titre', $fr->label());
    self::assertSame('Bonjour', self::headingText($fr));
    // The outdated flag is cleared, and a new revision was cut.
    self::assertFalse((bool) $fr->get('content_translation_outdated')->value);
    self::assertSame($before + 1, $this->revisionCount($storage, $id));
  }

  /**
   * A save carrying a stale source fingerprint is flagged as a concurrent edit.
   *
   * The editor remembers the source hash it loaded and sends it on every
   * autosave; when the source changes in Canvas underneath an open draft, the
   * next save response reports `sourceChanged` so the editor can prompt a
   * review.
   */
  public function testConcurrentSourceEditFlagsSaveResponse(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // The editor opens the translation and remembers the source fingerprint.
    $doc = self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'));
    $base = $doc['sourceHash'];
    self::assertNotEmpty($base);

    // A save against the current source reports no conflict.
    $res = self::decode($controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch(['title' => 'Titre'], $base)));
    self::assertFalse($res['sourceChanged']);
    self::assertSame($base, $res['sourceHash']);

    // The source changes in Canvas (new revision).
    $source = $storage->loadUnchanged($id);
    \assert($source instanceof Page);
    $source->set('title', 'English title (edited)');
    $source->setNewRevision(TRUE);
    $source->save();

    // The next autosave still carries the stale basis → flagged as changed, and
    // the response returns the fresh fingerprint to reset the editor's basis.
    $res = self::decode($controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch(['title' => 'Titre'], $base)));
    self::assertTrue($res['sourceChanged']);
    self::assertNotSame($base, $res['sourceHash']);
  }

  /**
   * The dashboard reports how many source strings changed for an outdated cell.
   */
  public function testDashboardReportsChangedStringCount(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $controller->publish($storage->loadUnchanged($id), 'fr');

    // Up to date → no changed-string count.
    self::assertNull($this->dashboardState($controller, $id, 'fr')['changedStrings']);

    // Change BOTH the title and the heading source.
    $source = $storage->loadUnchanged($id);
    \assert($source instanceof Page);
    $source->set('title', 'English title (edited)');
    $source->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)
      ->setInput(['text' => 'Hello again', 'element' => 'h1']);
    $source->setNewRevision(TRUE);
    $source->save();

    $state = $this->dashboardState($controller, $id, 'fr');
    self::assertSame(TranslationStatus::OUTDATED, $state['status']);
    self::assertSame(2, $state['changedStrings']);
  }

  /**
   * Regression: a translation whose values equal the source must still publish.
   *
   * Canvas's diff-based auto-save publish drops fields equal to the source, so
   * "copy source" on a new translation produced an empty published translation.
   * Our publish writes the values unconditionally.
   */
  public function testCopySourcePublishesNonEmptyTranslation(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'Brand Name',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Acme', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Translate with values identical to the source (as "copy source" does).
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Brand Name',
      'components.0.text' => 'Acme',
    ]));
    $controller->publish($storage->loadUnchanged($id), 'fr');

    // The French translation must carry the (source-identical) values, not be
    // empty, and the English source is untouched.
    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertTrue($published->hasTranslation('fr'));
    self::assertSame('Brand Name', $published->getTranslation('fr')->label());
    self::assertSame('Acme', self::headingText($published->getTranslation('fr')));
    // Values equal to the source count as untranslated (source fallback), so
    // completeness is 0 — but the content is present and renders, not lost.
    self::assertSame(0, $this->dashboardState($controller, $id, 'fr')['percent']);
  }

  /**
   * Tests that partially translating a page keeps the untranslated props.
   *
   * Regression: they must not be emptied or invalidated.
   *
   * The editor sends every field, including untranslated ones as ''. Writing
   * those empties used to wipe required props (e.g. heading `text`), which both
   * lost content and threw EntityStorageException ("property text is required")
   * on publish. Untranslated props must keep their source value instead.
   */
  public function testPartialTranslationKeepsSourceForUntranslatedProps(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'Help Center',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Welcome', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Translate only the title; the editor sends the (required) heading text as
    // ''.
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Centre d’aide',
      'components.0.text' => '',
    ]));
    // Must not throw (the required heading text kept its source value).
    $controller->publish($storage->loadUnchanged($id), 'fr');

    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertSame('Centre d’aide', $published->getTranslation('fr')->label());
    // The untranslated, required heading text fell back to the source — not
    // empty.
    self::assertSame('Welcome', self::headingText($published->getTranslation('fr')));
    // English untouched.
    self::assertSame('Welcome', self::headingText($published));

    // The editor shows the untranslated heading as empty (still to do); the
    // translated title shows through.
    $doc = self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'));
    $rows = self::allRows($doc);
    self::assertSame('', $rows['components.0.text']['target']);
    self::assertSame('Centre d’aide', $rows['title']['target']);
  }

  /**
   * Tests that batch publishing several languages of a page cuts one revision.
   *
   * The source and untargeted languages are left untouched and the published
   * drafts are cleared.
   */
  public function testBatchPublishPublishesAsOneRevisionPerPage(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $controller = ApiTranslateController::create($this->container);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));
    $controller->saveDraft($storage->loadUnchanged($id), 'de', self::patch([
      'title' => 'Titel',
      'components.0.text' => 'Hallo',
    ]));

    $before = $this->revisionCount($storage, $id);
    $response = $controller->publishBatch(self::batchRequest([
      ['id' => $id, 'langcode' => 'fr'],
      ['id' => $id, 'langcode' => 'de'],
    ]));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(2, self::decode($response)['published']);

    // Two languages of one page produced exactly one new revision.
    self::assertSame($before + 1, $this->revisionCount($storage, $id));

    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertSame('Bonjour', self::headingText($published->getTranslation('fr')));
    self::assertSame('Hallo', self::headingText($published->getTranslation('de')));
    // Source untouched.
    self::assertSame('Hello', self::headingText($published));
    self::assertSame('English title', $published->label());
    // Both drafts cleared.
    self::assertFalse($draftStore->has("canvas_page:$id:fr"));
    self::assertFalse($draftStore->has("canvas_page:$id:de"));
  }

  /**
   * Tests that batch publish is all-or-nothing.
   *
   * If any requested item cannot be published (here a language with no
   * draft), none of the batch is published and every draft is left intact.
   */
  public function testBatchPublishIsAllOrNothing(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $controller = ApiTranslateController::create($this->container);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Only French has a draft; German does not.
    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));

    $response = $controller->publishBatch(self::batchRequest([
      ['id' => $id, 'langcode' => 'fr'],
      ['id' => $id, 'langcode' => 'de'],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    // Nothing was published — French was not committed to storage.
    $stored = $storage->loadUnchanged($id);
    \assert($stored instanceof Page);
    self::assertFalse($stored->hasTranslation('fr'));
    // The French draft is still intact.
    self::assertTrue($draftStore->has("canvas_page:$id:fr"));
  }

  /**
   * Deleting a page clears a draft for a language never saved as a translation.
   *
   * The published page has no `fr` translation — only a pending `fr` draft in
   * the module's draft store. DraftCleanupHooks must clear every draft keyed to
   * the deleted page, so no orphaned draft remains.
   */
  public function testDeletingPageClearsDraftOnlyLanguage(): void {
    $controller = ApiTranslateController::create($this->container);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Brouillon']));
    // The draft exists, but the stored page has no fr translation.
    self::assertTrue($draftStore->has("canvas_page:$id:fr"));
    self::assertFalse($storage->loadUnchanged($id)->hasTranslation('fr'));

    $storage->loadUnchanged($id)->delete();
    self::assertFalse($draftStore->has("canvas_page:$id:fr"));
  }

  /**
   * A pending translation draft is invisible to Canvas's publish workflow.
   *
   * Translation text lives in this module's own store, never in
   * canvas.auto_save, so it appears in neither Canvas's pending-changes list
   * nor a "publish all": publishing the page's default-language content leaves
   * the draft untouched.
   */
  public function testTranslationDraftIsNotPublishedByCanvas(): void {
    $controller = ApiTranslateController::create($this->container);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    $autoSave = $this->container->get(AutoSaveManager::class);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // A translator starts a French translation.
    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));
    self::assertTrue($draftStore->has("canvas_page:$id:fr"));

    // The draft is in neither Canvas's auto-save store nor its pending list.
    self::assertArrayNotHasKey("canvas_page:$id:fr", $autoSave->getAllAutoSaveList(FALSE, FALSE));
    self::assertArrayNotHasKey("canvas_page:$id:fr", $this->getAutoSaveStatesFromServer());

    // Make a default-language (English) edit and publish it through Canvas's
    // "publish all" — the workflow that used to sweep up translation drafts.
    $edited = $storage->loadUnchanged($id);
    \assert($edited instanceof Page);
    $edited->set('title', 'English title updated');
    $autoSave->saveEntity($edited);
    $response = $this->makePublishAllRequest();
    self::assertTrue($response->isSuccessful(), (string) $response->getContent());

    // The English edit published.
    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertSame('English title updated', $published->label());
    // The French translation was NOT published, and the draft is intact.
    self::assertFalse($published->hasTranslation('fr'));
    self::assertTrue($draftStore->has("canvas_page:$id:fr"));
  }

  /**
   * Publishing applies the draft's text onto the CURRENT stored structure.
   *
   * A structural change made after a draft was saved (here a second component
   * added to the page) must survive publishing the draft: the published
   * translation carries the new structure plus the translated text, never the
   * stale structure the draft was authored against.
   */
  public function testPublishAppliesDraftOntoCurrentStructure(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Translate the (only) heading.
    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));

    // The page structure changes after the draft: a second component is added.
    $edited = $storage->loadUnchanged($id);
    \assert($edited instanceof Page);
    $edited->get('components')->appendItem([
      'uuid' => self::UUID_B,
      'component_id' => 'sdc.canvas_test_sdc.heading',
      'component_version' => $this->getHeadingComponentVersion(),
      'inputs' => ['text' => 'World', 'element' => 'h1'],
      'label' => 'Heading 2',
    ]);
    $edited->setNewRevision(TRUE);
    $edited->save();

    // Publish the French draft.
    $controller->publish($storage->loadUnchanged($id), 'fr');

    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    $fr = $published->getTranslation('fr');
    \assert($fr instanceof Page);
    // The published translation carries the CURRENT structure (both
    // components).
    self::assertCount(2, $fr->get('components')->getValue());
    // The first heading is translated and the second falls back to source.
    self::assertSame('Bonjour', self::itemText($fr, self::UUID_A));
    self::assertSame('World', self::itemText($fr, self::UUID_B));
  }

  /**
   * Unapprove withdraws a prior approval and is a no-op when not approved.
   */
  public function testUnapproveWithdrawsApproval(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // Save a French draft.
    $controller->saveDraft($page, 'fr', self::patch(['title' => 'Titre', 'components.0.text' => 'Bonjour']));

    // Unapprove when not yet approved is a no-op → returns approved: FALSE.
    self::assertFalse(self::decode($controller->unapprove($storage->loadUnchanged($id), 'fr'))['approved']);

    // Approve, then unapprove → approval is withdrawn.
    self::assertTrue(self::decode($controller->approve($storage->loadUnchanged($id), 'fr'))['approved']);
    self::assertFalse(self::decode($controller->unapprove($storage->loadUnchanged($id), 'fr'))['approved']);

    // The editor doc also reflects the cleared approval.
    self::assertFalse(self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'))['approved']);
  }

  /**
   * A component whose every prop shape is untranslatable offers no rows.
   *
   * Non-text prop shapes (datetime/date-only, email, integer and boolean) are
   * never offered for translation. Extracting them used to WSOD with an
   * `AssertionError` in Canvas's ComponentInputsTranslatablesExtractor, thrown
   * both by the dashboard (`items()`) and the editor (`getTranslation()`); now
   * they are excluded, and publishing a translation leaves them identical to
   * the source (the symmetric-translation invariant: only translatable input
   * values differ across languages).
   *
   * @see https://www.drupal.org/project/canvas_translate/issues/3607284
   * @see https://www.drupal.org/project/canvas/issues/3591734
   * @see \Drupal\Tests\canvas\Traits\ComponentTreeWithAllSymmetricalTranslationEdgeCasesTrait
   */
  public function testUntranslatablePropShapesOfferNoRows(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $shapes = $this->container->get('entity_type.manager')
      ->getStorage('component')->load('sdc.canvas_test_translation.untranslatable-prop-shapes');
    \assert($shapes instanceof Component);

    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
        [
          'uuid' => self::UUID_B,
          'component_id' => 'sdc.canvas_test_translation.untranslatable-prop-shapes',
          'component_version' => $shapes->getActiveVersion(),
          'inputs' => [
            'date' => '2024-01-15',
            'email' => 'person@example.com',
            'count' => 42,
            'flag' => TRUE,
          ],
          'label' => 'Shapes',
        ],
      ],
    ]);
    $page->save();
    $id = (int) $page->id();

    // The dashboard (items()) extracts every page's translatables to compute
    // completeness; with the untranslatable component present this used to
    // throw an AssertionError. It now returns a clean 0%-complete cell.
    // @see https://www.drupal.org/project/canvas_translate/issues/3607284
    self::assertSame(0, $this->dashboardState($controller, $id, 'fr')['percent']);

    // The heading's text is offered; none of the untranslatable component's
    // props is (extracting them used to trigger a WSOD).
    $rows = self::allRows(self::decode($controller->getTranslation($page, 'fr')));
    self::assertSame('Hello', $rows['components.0.text']['source']);
    foreach (['date', 'email', 'count', 'flag'] as $prop) {
      self::assertArrayNotHasKey("components.1.$prop", $rows);
    }

    // Translate only the heading, then publish.
    $controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre',
      'components.0.text' => 'Bonjour',
    ]));
    $controller->publish($storage->loadUnchanged($id), 'fr');

    // The untranslatable component's inputs are shared verbatim with the
    // source.
    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    $source_item = $published->get('components')->getComponentTreeItemByUuid(self::UUID_B);
    $fr_item = $published->getTranslation('fr')->get('components')->getComponentTreeItemByUuid(self::UUID_B);
    \assert($source_item instanceof ComponentTreeItem && $fr_item instanceof ComponentTreeItem);
    self::assertSame($source_item->getInputs(), $fr_item->getInputs());
    // …and the heading was translated.
    self::assertSame('Bonjour', self::headingText($published->getTranslation('fr')));
  }

  /**
   * Counts stored revisions for one page.
   */
  private function revisionCount(object $storage, int $id): int {
    return (int) $storage->getQuery()
      ->allRevisions()
      ->condition('id', $id)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Builds a publish-batch POST request for the given {id, langcode} items.
   */
  private static function batchRequest(array $items): Request {
    $request = Request::create('/canvas-translate/api/publish-batch', 'POST', content: (string) \json_encode(['items' => $items]));
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  /**
   * Returns the dashboard state for one page + language.
   */
  private function dashboardState(ApiTranslateController $controller, int $id, string $langcode): array {
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')))['items'];
    foreach ($items as $item) {
      if ($item['id'] === $id) {
        return $item['translations'][$langcode];
      }
    }
    self::fail("Page $id not found in dashboard.");
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
   * Returns the first heading instance's text input.
   */
  private static function headingText(Page $page): mixed {
    return self::itemText($page, self::UUID_A);
  }

  /**
   * Returns a component instance's text input, by UUID.
   */
  private static function itemText(Page $page, string $uuid): mixed {
    $list = $page->get('components');
    \assert($list instanceof ComponentTreeItemList);
    $item = $list->getComponentTreeItemByUuid($uuid);
    \assert($item instanceof ComponentTreeItem);
    return $item->getInputs()['text'] ?? NULL;
  }

  /**
   * Builds a PATCH request carrying a `values` payload.
   *
   * With an optional basis hash.
   */
  private static function patch(array $values, ?string $baseSourceHash = NULL): Request {
    $body = ['values' => $values];
    if ($baseSourceHash !== NULL) {
      $body['baseSourceHash'] = $baseSourceHash;
    }
    $request = Request::create('/', 'PATCH', content: (string) \json_encode($body));
    $request->headers->set('Content-Type', 'application/json');
    return $request;
  }

  /**
   * Decodes a JSON response body to an array.
   */
  private static function decode(JsonResponse $response): array {
    return \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
  }

  /**
   * Flattens a getTranslation() document to rows keyed by their `key`.
   */
  private static function allRows(array $doc): array {
    $rows = [];
    foreach ($doc['groups'] as $group) {
      foreach ($group['rows'] as $row) {
        $rows[$row['key']] = $row;
      }
    }
    return $rows;
  }

  /**
   * Creates a saved one-heading page and returns [page, id].
   *
   * @return array{0: \Drupal\canvas\Entity\Page, 1: int}
   *   The page and its ID.
   */
  private function createHeadingPage(): array {
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'Heading',
        ],
      ],
    ]);
    $page->save();
    return [$page, (int) $page->id()];
  }

  /**
   * Marks the given canvas_page base fields non-translatable.
   *
   * Like Canvas's shipped overrides, which a config import without the
   * language settings restores.
   */
  private function makeFieldsUntranslatable(array $field_names): void {
    $base = $this->container->get('entity_field.manager')->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID);
    foreach ($field_names as $field_name) {
      $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, $field_name)
        ?? BaseFieldOverride::createFromBaseFieldDefinition($base[$field_name], Page::ENTITY_TYPE_ID);
      $override->set('translatable', FALSE)->save();
    }
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Refuses everything when NO page field is translatable.
   *
   * With every written field non-translatable, any published "translation"
   * would land on the field values shared with the source language and
   * overwrite the original page, so every read/publish path must refuse with
   * a structured 409 (the SPA hides the workspace behind setup instructions)
   * and the page must stay untouched.
   */
  public function testRefusesWhenNoPageFieldIsTranslatable(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $draftStore = $this->container->get(TranslationDraftStore::class);
    [$page, $id] = $this->createHeadingPage();

    // A draft saved while translatability was still configured correctly.
    $save = self::decode($controller->saveDraft($page, 'fr', self::patch([
      'title' => 'Titre français',
      'components.0.text' => 'Bonjour',
    ])));

    $this->makeFieldsUntranslatable(ApiTranslateController::WRITTEN_FIELDS);

    // The dashboard tells the SPA to hide the workspace.
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')));
    self::assertTrue($items['translationBlocked']);

    // The editor refuses to load, with the structured payload the SPA needs.
    $response = $controller->getTranslation($storage->loadUnchanged($id), 'fr');
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertSame('translation_not_enabled', self::decode($response)['code']);

    // Saving and publishing refuse (single and batch) instead of overwriting
    // the source.
    self::assertSame(Response::HTTP_CONFLICT, $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch(['title' => 'X']))->getStatusCode());
    self::assertSame(Response::HTTP_CONFLICT, $controller->publish($storage->loadUnchanged($id), 'fr')->getStatusCode());
    $batch = self::batchRequest([['id' => $id, 'langcode' => 'fr']]);
    self::assertSame(Response::HTTP_CONFLICT, $controller->publishBatch($batch)->getStatusCode());

    // The source is untouched and the draft survives for after the fix.
    $unchanged = $storage->loadUnchanged($id);
    \assert($unchanged instanceof Page);
    self::assertSame('English title', $unchanged->getUntranslated()->label());
    self::assertSame('Hello', self::headingText($unchanged->getUntranslated()));
    self::assertTrue($draftStore->has($save['savedKey']));
  }

  /**
   * Refuses when content translation is disabled for the bundle entirely.
   *
   * The field definitions may still claim translatability (base field
   * overrides survive), but with the bundle not translation-enabled nothing
   * can be translated — the block must key off the bundle, not just fields.
   */
  public function testRefusesWhenBundleTranslationDisabled(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    [, $id] = $this->createHeadingPage();

    $this->container->get('content_translation.manager')->setEnabled(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, FALSE);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')));
    self::assertTrue($items['translationBlocked']);

    $response = $controller->getTranslation($storage->loadUnchanged($id), 'fr');
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    self::assertSame('translation_not_enabled', self::decode($response)['code']);
    self::assertSame(Response::HTTP_CONFLICT, $controller->publish($storage->loadUnchanged($id), 'fr')->getStatusCode());
  }

  /**
   * A single non-translatable field is a normal site configuration choice.
   *
   * It quietly disappears from the editor rows (no warning anywhere), drafted
   * values for it are stripped, publish writes everything else and never
   * touches the excluded field's (source-shared) storage.
   */
  public function testSingleUntranslatableFieldIsExcludedGracefully(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    [$page, $id] = $this->createHeadingPage();
    $page->set('description', 'English description')->save();

    $this->makeFieldsUntranslatable(['description']);

    // The workspace stays open.
    $items = self::decode($controller->items(Request::create('/canvas-translate/api/items')));
    self::assertFalse($items['translationBlocked']);

    // The editor just omits the excluded field.
    $doc = self::decode($controller->getTranslation($storage->loadUnchanged($id), 'fr'));
    $rows = self::allRows($doc);
    self::assertArrayNotHasKey('description', $rows);
    self::assertArrayHasKey('title', $rows);
    self::assertArrayHasKey('components.0.text', $rows);

    // A draft carrying a value for the excluded field has it stripped...
    $controller->saveDraft($storage->loadUnchanged($id), 'fr', self::patch([
      'title' => 'Titre français',
      'description' => 'Description française',
      'components.0.text' => 'Bonjour',
    ]));

    // ...so publish writes the translatable fields and nothing else.
    $response = $controller->publish($storage->loadUnchanged($id), 'fr');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $published = $storage->loadUnchanged($id);
    \assert($published instanceof Page);
    self::assertSame('Titre français', $published->getTranslation('fr')->label());
    self::assertSame('Bonjour', self::headingText($published->getTranslation('fr')));
    // The shared description is intact in every language.
    self::assertSame('English description', $published->getUntranslated()->get('description')->value);
    self::assertSame('English description', $published->getTranslation('fr')->get('description')->value);
  }

  /**
   * A user without page update access cannot read, approve, or unapprove.
   */
  public function testReadAndApprovalEndpointsRequireUpdateAccess(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $page = Page::create(['title' => 'English title', 'status' => TRUE]);
    $page->save();
    $id = (int) $page->id();

    $restricted = $this->createUser(['translate canvas content']);
    $this->setCurrentUser($restricted);
    $reloaded = $storage->loadUnchanged($id);

    self::assertSame(Response::HTTP_FORBIDDEN, $controller->getTranslation($reloaded, 'fr')->getStatusCode());
    self::assertSame(Response::HTTP_FORBIDDEN, $controller->approve($reloaded, 'fr')->getStatusCode());
    self::assertSame(Response::HTTP_FORBIDDEN, $controller->unapprove($reloaded, 'fr')->getStatusCode());
  }

  /**
   * Preview of a page the user cannot update is access-denied.
   */
  public function testPreviewRequiresUpdateAccess(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $page = Page::create(['title' => 'English title', 'status' => TRUE]);
    $page->save();
    $id = (int) $page->id();

    $restricted = $this->createUser(['translate canvas content']);
    $this->setCurrentUser($restricted);

    $this->expectException(AccessDeniedHttpException::class);
    PreviewController::create($this->container)
      ->preview($storage->loadUnchanged($id), 'fr');
  }

  /**
   * Tests the dashboard status filter with no language selected.
   *
   * An item passes when ANY non-source language has the chosen status.
   */
  public function testStatusFilterWithoutLanguage(): void {
    $controller = ApiTranslateController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $drafted = Page::create([
      'title' => 'Has draft',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'Hello', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $drafted->save();
    $untouched = Page::create([
      'title' => 'No draft',
      'status' => TRUE,
      'components' => [[
        'uuid' => self::UUID_A,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $this->getHeadingComponentVersion(),
        'inputs' => ['text' => 'World', 'element' => 'h1'],
        'label' => 'Heading',
      ],
      ],
    ]);
    $untouched->save();

    $controller->saveDraft($storage->loadUnchanged((int) $drafted->id()), 'fr', self::patch([
      'title' => 'Brouillon',
    ]));

    $draftItems = self::decode($controller->items(Request::create('/canvas-translate/api/items?status=draft')))['items'];
    $draftIds = \array_column(\array_filter($draftItems, static fn(array $i): bool => $i['kind'] === 'content'), 'id');
    self::assertContains((int) $drafted->id(), $draftIds);
    self::assertNotContains((int) $untouched->id(), $draftIds);

    $ntItems = self::decode($controller->items(Request::create('/canvas-translate/api/items?status=not_translated')))['items'];
    $ntIds = \array_column(\array_filter($ntItems, static fn(array $i): bool => $i['kind'] === 'content'), 'id');
    self::assertContains((int) $untouched->id(), $ntIds);
    self::assertNotContains((int) $drafted->id(), $ntIds);

    $comboItems = self::decode($controller->items(Request::create('/canvas-translate/api/items?status=draft&langcode=fr')))['items'];
    $comboIds = \array_column(\array_filter($comboItems, static fn(array $i): bool => $i['kind'] === 'content'), 'id');
    self::assertContains((int) $drafted->id(), $comboIds);
    self::assertNotContains((int) $untouched->id(), $comboIds);
  }

  /**
   * Tests that the dashboard ignores a `limit` query param.
   *
   * Regression: it must return every item, as there is no pagination UI.
   */
  public function testDashboardIgnoresLimitParam(): void {
    $controller = ApiTranslateController::create($this->container);
    foreach (['One', 'Two', 'Three'] as $title) {
      Page::create([
        'title' => $title,
        'status' => TRUE,
        'components' => [[
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $this->getHeadingComponentVersion(),
          'inputs' => ['text' => $title, 'element' => 'h1'],
          'label' => 'Heading',
        ],
        ],
      ])->save();
    }

    $response = self::decode($controller->items(Request::create('/canvas-translate/api/items?limit=1')));
    $contentItems = \array_filter($response['items'], static fn(array $i): bool => $i['kind'] === 'content');
    self::assertCount(3, $contentItems);
    self::assertCount($response['total'], $response['items']);
  }

  /**
   * Core's Translate operation/tab lands on the page's overview, not the list.
   */
  public function testTranslateOverviewRedirectsToPageOverview(): void {
    [, $id] = $this->createHeadingPage();

    $response = $this->request(Request::create("/page/$id/translations"));

    self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    self::assertStringEndsWith("/canvas/app/canvas_translate/page/$id", (string) $response->headers->get('Location'));
  }

  /**
   * A translation's row edits that translation; the original's row is as-is.
   */
  public function testTranslationRowOperations(): void {
    [$page, $id] = $this->createHeadingPage();
    $page->addTranslation('fr', $page->toArray())->set('title', 'Titre')->save();
    $list_builder = $this->container->get('entity_type.manager')->getListBuilder(Page::ENTITY_TYPE_ID);

    $operations = $list_builder->getOperations($page->getTranslation('fr'));
    self::assertStringEndsWith("/canvas/app/canvas_translate/page/$id/fr", $operations['canvas_translate_edit']['url']->toString());
    self::assertSame('Edit original', (string) $operations['edit']['title']);
    self::assertLessThan($operations['edit']['weight'], $operations['canvas_translate_edit']['weight']);

    $operations = $list_builder->getOperations($page);
    self::assertArrayNotHasKey('canvas_translate_edit', $operations);
    self::assertSame('Edit', (string) $operations['edit']['title']);

    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION, 'translate any entity']);
    $operations = $list_builder->getOperations($page->getTranslation('fr'));
    self::assertArrayNotHasKey('canvas_translate_edit', $operations);
    self::assertSame('Edit original', (string) $operations['edit']['title']);
  }

  /**
   * The Canvas language switcher gets edit/add links into this module.
   */
  public function testLayoutTranslationLinks(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    [$page, $id] = $this->createHeadingPage();
    $page->addTranslation('fr', $page->toArray())->set('title', 'Titre')->save();
    $url = "/canvas/api/v0/layout/canvas_page/$id";

    $links = self::decode($this->request(Request::create($url)))['translations']['links'];
    self::assertStringEndsWith("/canvas/app/canvas_translate/page/$id/fr", $links['fr']['edit-form']);
    self::assertArrayNotHasKey('create', $links['fr']);
    // Canvas's own delete link is kept.
    self::assertArrayHasKey('delete-form', $links['fr']);
    self::assertStringEndsWith("/canvas/app/canvas_translate/page/$id/de", $links['de']['create']);
    self::assertArrayNotHasKey('edit-form', $links['de']);
    self::assertArrayNotHasKey('en', $links);

    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION, 'translate any entity']);
    $links = self::decode($this->request(Request::create($url)))['translations']['links'];
    self::assertSame(['delete-form'], \array_keys($links['fr']));
    self::assertArrayNotHasKey('de', $links);
  }

  /**
   * The decorator has every controller method a Canvas route calls.
   */
  public function testLayoutDecoratorCoversRouteMethods(): void {
    self::assertInstanceOf(ApiLayoutControllerDecorator::class, $this->container->get(ApiLayoutController::class));
    $prefix = ApiLayoutController::class . '::';
    $checked = $missing = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $controller = \ltrim((string) $route->getDefault('_controller'), '\\');
      if (!\str_starts_with($controller, $prefix)) {
        continue;
      }
      $checked[] = $name;
      if (!\method_exists(ApiLayoutControllerDecorator::class, \substr($controller, \strlen($prefix)))) {
        $missing[] = $name;
      }
    }
    self::assertContains('canvas.api.layout.get', $checked);
    self::assertSame([], $missing);
  }

  /**
   * The per-page overview lists only the requested page, and no config items.
   */
  public function testDashboardFiltersByPageId(): void {
    $controller = ApiTranslateController::create($this->container);
    [, $id] = $this->createHeadingPage();
    [, $other_id] = $this->createHeadingPage();

    $response = self::decode($controller->items(Request::create("/canvas-translate/api/items?id=$id")));

    self::assertSame(1, $response['total']);
    self::assertSame('content', $response['items'][0]['kind']);
    self::assertSame($id, $response['items'][0]['id']);
    self::assertSame(['en', 'fr'], \array_keys($response['items'][0]['translations']));

    $unfiltered = self::decode($controller->items(Request::create('/canvas-translate/api/items')));
    self::assertContains($other_id, \array_column($unfiltered['items'], 'id'));
  }

}
