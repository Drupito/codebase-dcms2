<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\trash\Trash;
use Drupal\trash\TrashStorageInterface;
use Drupal\trash_test\Entity\TrashTestEntity;
use Drupal\trash_test\TrashTestEntityStorageInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests basic trash functionality.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashIntegrationTest extends TrashKernelTestBase {

  use EntityActionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
  }

  /**
   * The delete form warns when a newer pending revision exists.
   */
  public function testDeleteFormPendingRevisionWarning(): void {
    $this->setUpCurrentUser();
    $storage = $this->getEntityTypeManager()->getStorage('node');
    $node = $this->createNode(['type' => 'article', 'title' => 'Live']);

    // Without a pending revision there is no warning.
    $form_object = $this->getEntityTypeManager()->getFormObject('node', 'delete')->setEntity($node);
    $form = $this->container->get('form_builder')->buildForm($form_object, new FormState());
    $this->assertArrayNotHasKey('trash_pending_revision_warning', $form);

    // With a newer pending revision the warning shows up.
    $pending = $storage->createRevision($node, FALSE);
    $pending->save();
    $node = $storage->load($node->id());
    $form_object = $this->getEntityTypeManager()->getFormObject('node', 'delete')->setEntity($node);
    $form = $this->container->get('form_builder')->buildForm($form_object, new FormState());
    $this->assertArrayHasKey('trash_pending_revision_warning', $form);
  }

  /**
   * Test trashing entities.
   */
  public function testDeletion(): void {
    $entity = TrashTestEntity::create();
    $entity->save();
    $entity_id = $entity->id();

    $this->assertNotEmpty(TrashTestEntity::load($entity_id));
    $this->assertFalse(Trash::entityIsDeleted($entity));
    $this->assertTrue($entity->get('deleted')->isEmpty());
    $this->assertNull($entity->get('deleted')->value);

    $entity->delete();

    // Test the default 'active' trash context.
    $this->assertEmpty(TrashTestEntity::load($entity_id), 'Deleted entities can not be loaded in the default (active) trash context.');

    // Test the 'ignore' trash context.
    $entity = $this->getTrashManager()->executeInTrashContext('ignore', function () use ($entity_id) {
      return TrashTestEntity::load($entity_id);
    });
    assert($entity instanceof ContentEntityInterface);
    $this->assertNotEmpty($entity, 'Deleted entities can still be loaded in the "ignore" trash context.');
    $this->assertTrue(Trash::entityIsDeleted($entity));
    $this->assertEquals(\Drupal::time()->getRequestTime(), $entity->get('deleted')->value);

    // Deleting an already-trashed entity again is a no-op: the reload in the
    // active trash context cannot see it, so it is skipped instead of
    // crashing, and no new deletion revision is created.
    $revision_count = $this->countAllRevisions('trash_test_entity', $entity_id);
    $entity->delete();
    $this->assertSame($revision_count, $this->countAllRevisions('trash_test_entity', $entity_id));

    $second_entity = TrashTestEntity::create();
    $second_entity->save();
    $second_entity_id = $second_entity->id();

    // Test the default 'active' trash context for multiple load.
    $entities = TrashTestEntity::loadMultiple();
    $this->assertCount(1, $entities);
    $this->assertEquals($second_entity_id, $entities[$second_entity_id]->id());

    // Test the 'ignore' trash context  for multiple load.
    $entities = $this->getTrashManager()->executeInTrashContext('ignore', function () {
      return TrashTestEntity::loadMultiple();
    });
    $this->assertCount(2, $entities);
    $this->assertEquals($entity_id, $entities[$entity_id]->id());
    $this->assertEquals($second_entity_id, $entities[$second_entity_id]->id());

    // A batch delete that contains an already-trashed entity skips it and
    // still processes the remaining entities.
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    $storage->delete([$entity, $entities[$second_entity_id]]);
    $this->assertEmpty(TrashTestEntity::load($second_entity_id));
    $second_entity = $this->loadTrashedEntity('trash_test_entity', $second_entity_id);
    $this->assertTrue(Trash::entityIsDeleted($second_entity));
  }

  /**
   * Test prevention of stale entities in the caches.
   */
  public function testPersistentCache(): void {
    $live_node = $this->createNode(['type' => 'article']);
    $live_node->save();

    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $nid = $live_node->id();
    $loaded = $storage->load($nid);

    // Sanity checks that caches work.
    $this->assertNotEmpty($loaded);
    $this->assertSame(1, $storage->getLatestRevisionId($nid));
    $this->assertEquals(1, $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));

    $loaded->delete();

    // Deleting an entity should reset the in-memory and persistent entity
    // caches per ids [and revisions in D11].
    $this->assertEmpty($storage->load($nid));
    $this->assertNull($storage->getLatestRevisionId($nid));
    // @todo This returns the pre-deleted revision, looks like a bug.
    $this->assertSame(1, $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));

    // Deactivate the Trash context and fill the caches.
    $this->getTrashManager()->setTrashContext('inactive');
    $this->assertNotEmpty($storage->load($nid));
    $this->assertSame(2, $storage->getLatestRevisionId($nid));
    $this->assertSame(2, $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));

    // Re-activate the caches and ensure the caches have been cleared.
    $this->getTrashManager()->setTrashContext('active');
    $this->assertEmpty($storage->load($nid));
    $this->assertNull($storage->getLatestRevisionId($nid));
    // @todo This returns the pre-deleted revision, looks like a bug.
    $this->assertSame(1, $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));

    // Repeat the above with executeInTrashContext().
    $this->getTrashManager()->executeInTrashContext('inactive', function () use ($storage, $nid) {
      $this->assertNotEmpty($storage->load($nid));
      $this->assertSame(2, $storage->getLatestRevisionId($nid));
      $this->assertSame(
        2,
        $storage->getLatestTranslationAffectedRevisionId($nid, 'en')
      );
    });

    // Re-activate the caches and ensure the caches have been cleared.
    $this->assertEmpty($storage->load($nid));
    $this->assertNull($storage->getLatestRevisionId($nid));
    // @todo This returns the pre-deleted revision, looks like a bug.
    $this->assertSame(1, $storage->getLatestTranslationAffectedRevisionId($nid, 'en'));
  }

  /**
   * Tests Trash::entityIsDeleted() for disabled entity type.
   */
  public function testDisabledEntityType(): void {
    // Check a node bundle that's not trash-enabled.
    $nonDeletableNode = $this->createNode(['type' => 'page']);
    $nonDeletableNode->save();
    $this->assertFalse(Trash::entityIsDeleted($nonDeletableNode));

    $nonDeletableNode->delete();
    $this->assertEmpty(Node::load($nonDeletableNode->id()));

    // Check an entity type that's not trash-enabled.
    $nonDeletableEntity = $this->createUser(['administer users']);
    $nonDeletableEntity->save();
    $this->assertFalse(Trash::entityIsDeleted($nonDeletableEntity));
    $this->assertFalse($nonDeletableEntity->access('restore', $nonDeletableEntity));
    $this->assertFalse($nonDeletableEntity->access('purge', $nonDeletableEntity));

    $nonDeletableEntity->delete();
    $this->assertEmpty(User::load($nonDeletableEntity->id()));
  }

  /**
   * Tests TrashManager::isEntityTypeEnabled().
   */
  #[DataProvider('providerIsEntityTypeEnabled')]
  public function testIsEntityTypeEnabled($entity_type_id, $bundle, $enabled): void {
    $this->assertSame($enabled, $this->getTrashManager()->isEntityTypeEnabled($entity_type_id, $bundle));
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    $this->assertSame($enabled, $this->getTrashManager()->isEntityTypeEnabled($entity_type, $bundle));
  }

  /**
   * Data provider for testIsEntityTypeEnabled().
   */
  public static function providerIsEntityTypeEnabled(): array {
    return [
      // An entity type with a single bundle enabled.
      ['node', NULL, TRUE],
      ['node', 'article', TRUE],
      ['node', 'page', FALSE],
      // An entity type with all bundles enabled.
      ['trash_test_entity', NULL, TRUE],
      ['trash_test_entity', 'random_bundle_id', TRUE],
      // A disabled entity type.
      ['media', NULL, FALSE],
      ['media', 'random_bundle_id', FALSE],
    ];
  }

  /**
   * Tests that the trash context is appropriately switched based on the user.
   */
  public function testTrashContextSwitching(): void {
    /** @var \Drupal\Core\Session\AccountSwitcherInterface $account_switcher */
    $account_switcher = $this->container->get('account_switcher');
    $administer_trash_user = $this->createUser(['administer trash']);
    $access_trash_user = $this->createUser(['access trash']);
    $view_deleted_trash_user = $this->createUser(['view deleted entities']);
    $normal_user = $this->createUser();

    // The trash context should be 'active' by default.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // The trash context should stay the same.
    $account_switcher->switchTo($administer_trash_user);
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // The trash context should stay the same.
    $account_switcher->switchTo($access_trash_user);
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // The trash context should stay the same.
    $account_switcher->switchTo($view_deleted_trash_user);
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // The trash context should be switched if the "in_trash" query string is
    // set.
    \Drupal::request()->query->set('in_trash', '1');
    $account_switcher->switchTo($administer_trash_user);
    static::assertEquals('ignore', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    // The trash context should switch back to the 'active' state.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    $account_switcher->switchTo($access_trash_user);
    static::assertEquals('ignore', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    // The trash context should switch back to the 'active' state.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    $account_switcher->switchTo($view_deleted_trash_user);
    static::assertEquals('ignore', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    // The trash context should switch back to the 'active' state.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // The state should still be active as the user does not have the necessary
    // permissions.
    $account_switcher->switchTo($normal_user);
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // Assert that if the trash context is switched to 'ignore' even if it was
    // previously 'inactive' as the "in_trash" query string takes precedence.
    $this->getTrashManager()->setTrashContext('inactive');
    $account_switcher->switchTo($administer_trash_user);
    static::assertEquals('ignore', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    // The trash context should switch back to the 'active' state.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());

    // Assert that the trash context is now 'active' even if it was previously
    // 'inactive'.
    $this->getTrashManager()->setTrashContext('inactive');
    $account_switcher->switchTo($normal_user);
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
    $account_switcher->switchBack();
    // Should now be active.
    static::assertEquals('active', $this->getTrashManager()->getTrashContext());
  }

  /**
   * Tests that deleted entities are not stored in cache.
   */
  public function testDeletedEntitiesNotInCache(): void {
    $entity = TrashTestEntity::create();
    $entity->save();
    $entity_id = $entity->id();

    // Load the entity to ensure it gets cached.
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    $storage->resetCache();
    TrashTestEntity::load($entity_id);

    // Verify the entity is in the persistent cache.
    $persistent_cache = \Drupal::cache('entity');
    $cid = "values:trash_test_entity:$entity_id";
    $this->assertNotNull($persistent_cache->get($cid));

    // Verify the entity is in the memory cache.
    $memory_cache = \Drupal::service('entity.memory_cache');
    $this->assertNotNull($memory_cache->get($cid));

    // Delete the entity (soft-delete).
    $entity->delete();

    // The cache entries should be deleted after the entity is soft-deleted.
    $this->assertFalse($persistent_cache->get($cid));
    $this->assertFalse($memory_cache->get($cid));

    // Load the deleted entity in the 'ignore' trash context and verify caches
    // while still inside the context.
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($entity_id, $persistent_cache, $memory_cache, $cid) {
      TrashTestEntity::load($entity_id);

      // Verify the deleted entity is not in the persistent cache.
      $this->assertFalse($persistent_cache->get($cid));

      // Verify the deleted entity is not in the memory cache.
      $this->assertFalse($memory_cache->get($cid));
    });
  }

  /**
   * Tests getting the latest revision ID while switching trash contexts.
   */
  public function testGetLatestRevisionId(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);

    // Create a test entity (revision 1).
    $entity = $storage->create(['label' => 'Test entity']);
    assert($entity instanceof ContentEntityInterface);
    $entity->save();
    $default_revision_id = $entity->getRevisionId();

    // Create a pending revision (revision 2).
    $entity->setNewRevision(TRUE);
    $entity->isDefaultRevision(FALSE);
    $entity->save();
    $pending_revision_id = $entity->getRevisionId();

    // Soft-delete the entity (revision 3). delete() acts on a freshly loaded
    // default revision, so the deletion revision has to be read from a
    // reloaded entity, not the passed object.
    $storage->delete([$entity]);
    $entity = $this->forceLoadEntity($storage, $entity->id());
    $deleted_revision_id = $entity->getRevisionId();

    // Verify we have 3 distinct revisions.
    $this->assertEquals([1, 2, 3], [$default_revision_id, $pending_revision_id, $deleted_revision_id]);

    // Verify the correct value in the 'active' context after deletion.
    $this->assertEquals('active', $this->getTrashManager()->getTrashContext());
    $this->assertNull($storage->getLatestRevisionId($entity->id()));

    // Switch to the 'ignore' context and call getLatestRevisionId().
    $this->getTrashManager()->setTrashContext('ignore');
    $this->assertEquals($deleted_revision_id, $storage->getLatestRevisionId($entity->id()));

    // Switch back to the 'active' context.
    $this->getTrashManager()->setTrashContext('active');
    $this->assertNull($storage->getLatestRevisionId($entity->id()));

    // Restoring creates a new revision (revision 4).
    $this->getTrashManager()->setTrashContext('ignore');
    $storage->restoreFromTrash([$entity]);
    $entity = $this->forceLoadEntity($storage, $entity->id());
    $restored_revision_id = $entity->getRevisionId();
    $this->assertGreaterThan($deleted_revision_id, $restored_revision_id);

    // Restoring again un-deletes nothing, so it must not create a revision.
    $storage->restoreFromTrash([$entity]);
    $reloaded = $this->forceLoadEntity($storage, $entity->id());
    $this->assertSame($restored_revision_id, $reloaded->getRevisionId());
  }

  /**
   * Tests deleting and explicitly removing translations.
   */
  public function testTranslationDeletion() {
    $this->enableModules(['user', 'language']);
    $this->installConfig(['user', 'language']);
    $this->installEntitySchema('user');

    ConfigurableLanguage::createFromLangcode('sv')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

    $english = TrashTestEntity::create([
      'label' => 'Test 1 EN',
    ]);
    $english->addTranslation('sv', [
      'label' => 'Test 1 SV',
    ]);
    $english->addTranslation('de', [
      'label' => 'Test 1 DE',
    ]);
    $english->save();
    $swedish = $english->getTranslation('sv');
    $german = $english->getTranslation('de');
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals(0, $swedish->get('deleted')->value);
    $this->assertEquals(0, $german->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 SV', $swedish->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $request_time = \Drupal::time()->getRequestTime();

    // German deleted. English and Swedish automatically deleted.
    $german->delete();
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals($request_time, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // A whole-entity delete marks every translation, including the default
    // (English), as revision-translation-affected in the single deletion
    // revision.
    $this->assertTrue((bool) $english->isRevisionTranslationAffected());
    $this->assertTrue((bool) $german->isRevisionTranslationAffected());
    $this->assertTrue((bool) $swedish->isRevisionTranslationAffected());

    // All restored.
    Trash::restoreEntity($english);
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals(0, $german->get('deleted')->value);
    $this->assertEquals(0, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // Swedish deleted. English and German automatically deleted.
    $swedish->delete();
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals($request_time, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // All restored.
    Trash::restoreEntity($english);
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $english->save();
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals(0, $german->get('deleted')->value);
    $this->assertEquals(0, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // Swedish removed. German and English available. Removing a single
    // translation soft-deletes it in exactly one new revision and leaves the
    // prior revision (where Swedish is still live) untouched.
    $live_revision_id = $english->getRevisionId();
    $revision_count_before = $this->countAllRevisions('trash_test_entity', $english->id());
    $english->removeTranslation('sv');
    $english->save();
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals(0, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    $this->assertTrue((bool) $swedish->isRevisionTranslationAffected());
    $this->assertFalse((bool) $english->isRevisionTranslationAffected());
    $this->assertFalse((bool) $german->isRevisionTranslationAffected());

    $this->assertSame($revision_count_before + 1, $this->countAllRevisions('trash_test_entity', $english->id()));
    $this->assertNotSame($live_revision_id, $english->getRevisionId());
    [$prior_en, $prior_de, $prior_sv] = $this->reloadEntityAndTranslations($english->id(), $live_revision_id);
    $this->assertEquals(0, $prior_en->get('deleted')->value);
    $this->assertEquals(0, $prior_de->get('deleted')->value);
    $this->assertEquals(0, $prior_sv->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $prior_en->label());
    $this->assertEquals('Test 1 DE', $prior_de->label());
    $this->assertEquals('Test 1 SV', $prior_sv->label());

    // German and Swedish removed. English available.
    $english->removeTranslation('de');
    $english->save();
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // All deleted.
    $english->delete();
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals($request_time, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // Removing a translation still works when the original is not loadable in
    // the active context. Core's EntityStorageBase::doPreSave() sets the
    // original via loadUnchanged() while the trash context is still active,
    // before hooks run. For this fully trashed entity that returns NULL, so
    // entityPresave() must reload the original in the 'ignore' context.
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof ContentEntityStorageInterface);
    $this->assertNull($storage->loadUnchanged($english->id()), 'Trashed entity is not loadable in the active context.');
    $english = $this->forceLoadEntity($storage, $english->id());
    $english->removeTranslation('sv');
    $english->save();
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals($request_time, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertTrue((bool) $swedish->isRevisionTranslationAffected());
    $this->assertFalse((bool) $english->isRevisionTranslationAffected());
    $this->assertFalse((bool) $german->isRevisionTranslationAffected());

    // English restored. German and Swedish deleted.
    Trash::restoreEntity($english, ['en']);
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals($request_time, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
    $this->assertEquals('Test 1 SV', $swedish->label());

    // Removing a translation while editing another in the same save keeps the
    // edit and soft-deletes the removed translation.
    Trash::restoreEntity($english);
    [$english] = $this->reloadEntityAndTranslations($english->id());
    $english->removeTranslation('de');
    $english->getUntranslated()->set('label', 'Test 1 EN changed');
    $english->save();
    [$english, $german, $swedish] = $this->reloadEntityAndTranslations($english->id());
    $this->assertEquals(0, $english->get('deleted')->value);
    $this->assertEquals($request_time, $german->get('deleted')->value);
    $this->assertEquals(0, $swedish->get('deleted')->value);
    $this->assertEquals('Test 1 EN changed', $english->label());
    $this->assertEquals('Test 1 DE', $german->label());
  }

  /**
   * A translation soft-delete leaves an existing pending revision intact.
   */
  public function testTranslationSoftDeleteWithPendingRevision(): void {
    $this->enableModules(['language']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();

    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof ContentEntityStorageInterface);

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = $entity->id();

    // Create a pending (non-default) forward revision.
    $entity->setNewRevision(TRUE);
    $entity->isDefaultRevision(FALSE);
    $entity->getTranslation('de')->set('label', 'DE pending');
    $entity->save();
    $pending_revision_id = $entity->getRevisionId();

    // Soft-delete the German translation from the default revision.
    $entity = $storage->load($id);
    assert($entity instanceof ContentEntityInterface);
    $entity->removeTranslation('de');
    $entity->save();

    // German is trashed in the new default revision.
    $current = $this->forceLoadEntity($storage, $id);
    $this->assertTrue(Trash::entityIsDeleted($current->getTranslation('de')));

    // The pending revision still exists with its own content.
    $pending = $this->forceLoadEntity($storage, $id, revision_id: $pending_revision_id);
    $this->assertNotNull($pending);
    $this->assertEquals('DE pending', $pending->getTranslation('de')->label());
  }

  /**
   * Counts all revisions of an entity, ignoring trash state.
   */
  private function countAllRevisions(string $entity_type_id, string|int $id): int {
    return (int) $this->getEntityTypeManager()->getStorage($entity_type_id)->getQuery()
      ->addMetaData('trash', 'ignore')
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $id)
      ->count()
      ->execute();
  }

  /**
   * Reload an entity and its translations.
   *
   * @param string $id
   *   The entity id to reload.
   * @param string|null $revision_id
   *   (optional) A specific revision to load instead of the default revision.
   *
   * @return array{\Drupal\trash_test\Entity\TrashTestEntity, \Drupal\trash_test\Entity\TrashTestEntity, \Drupal\trash_test\Entity\TrashTestEntity}
   *   The English, German and Swedish translations of the entity.
   */
  private function reloadEntityAndTranslations(string $id, ?string $revision_id = NULL): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('trash_test_entity');
    /** @var \Drupal\trash_test\Entity\TrashTestEntity $english */
    $english = $this->forceLoadEntity($storage, $id, revision_id: $revision_id);

    return [$english, $english->getTranslation('de'), $english->getTranslation('sv')];
  }

  /**
   * Tests that hook_modules_installed preserves existing enabled_entity_types.
   */
  public function testModulesInstalledPreservesConfig(): void {
    // TrashKernelTestBase::setUp() seeds enabled_entity_types.node with a
    // specific bundle list, mimicking a distribution/profile that ships a
    // pre-configured trash.settings. Firing hook_modules_installed (as
    // happens at the end of a profile install) must not overwrite that
    // bundle list with an empty array.
    $before = \Drupal::config('trash.settings')->get('enabled_entity_types');
    $this->assertSame(['article'], $before['node']);

    \Drupal::moduleHandler()->invokeAll('modules_installed', [['node'], FALSE]);

    $after = \Drupal::config('trash.settings')->get('enabled_entity_types');
    $this->assertSame(['article'], $after['node']);

    // Nothing may change while config is syncing.
    \Drupal::moduleHandler()->invokeAll('modules_installed', [['path_alias'], TRUE]);
    $after = \Drupal::config('trash.settings')->get('enabled_entity_types');
    $this->assertArrayNotHasKey('path_alias', $after);
  }

  /**
   * Tests delete and restore in the request that enables an entity type.
   */
  public function testDeleteAndRestoreWithoutHandler(): void {
    // Enable an entity type by saving config, without the container rebuild
    // that registers its trash handler: this is the state for the rest of the
    // request that enables an entity type, since handlers are wired to the
    // trash manager at compile time.
    $config = \Drupal::configFactory()->getEditable('trash.settings');
    $enabled = $config->get('enabled_entity_types');
    $enabled['user'] = [];
    $config->set('enabled_entity_types', $enabled)->save();

    $this->assertTrue($this->getTrashManager()->isEntityTypeEnabled('user'));
    $this->assertNull($this->getTrashManager()->getHandler('user'));

    // Deleting and restoring must work without a registered handler.
    $account = $this->createUser();
    $account->delete();

    $storage = $this->getEntityTypeManager()->getStorage('user');
    assert($storage instanceof TrashStorageInterface);
    $this->assertNull($storage->load($account->id()));
    $trashed = $this->loadTrashedEntity('user', $account->id());
    $this->assertNotNull($trashed);
    $this->assertTrue(Trash::entityIsDeleted($trashed));

    $storage->restoreFromTrash([$trashed]);
    $restored = $storage->loadUnchanged($account->id());
    $this->assertNotNull($restored);
    $this->assertFalse(Trash::entityIsDeleted($restored));
  }

  /**
   * Tests detection of trash.settings drifting from the field layer.
   */
  public function testConfigDriftDetection(): void {
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('trash')
      ->addLogger($logger);
    $config = \Drupal::configFactory()->getEditable('trash.settings');
    $enabled = $config->get('enabled_entity_types');

    $storage_not_supported = static fn (array $record): bool => ($record['context']['@entity_type_id'] ?? '') === 'entity_test_no_id'
      && str_contains($record['message'], 'storage does not support Trash');

    // Entity types that aren't defined yet must not be reported on config
    // save, because a config sync saves trash.settings before installing the
    // modules that provide them.
    $enabled['foo'] = [];
    $enabled['entity_test_no_id'] = [];
    $config->set('enabled_entity_types', $enabled)->save();
    $this->assertFalse($logger->hasRecords(RfcLogLevel::ERROR));
    unset($enabled['foo']);

    // Creating an enabled entity type whose storage cannot support trash must
    // be reported.
    $this->enableModules(['entity_test']);
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('trash')
      ->addLogger($logger);
    $this->installEntitySchema('entity_test_no_id');
    $this->assertTrue($logger->hasRecordThatPasses($storage_not_supported, RfcLogLevel::ERROR));

    // Enabling an existing entity type whose storage cannot support trash
    // must be reported on config save.
    $logger->reset();
    $config = \Drupal::configFactory()->getEditable('trash.settings');
    $config->set('enabled_entity_types', $enabled)->save();
    $this->assertTrue($logger->hasRecordThatPasses($storage_not_supported, RfcLogLevel::ERROR));
    unset($enabled['entity_test_no_id']);

    // Enabling an entity type whose 'deleted' field belongs to another module
    // must be reported as well: the field cannot be installed over, so every
    // query for that type would filter on the foreign field.
    $foreign_field = BaseFieldDefinition::create('timestamp')
      ->setLabel('Deleted')
      ->setRevisionable(TRUE);
    \Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition('deleted', 'media', 'media', $foreign_field);
    $enabled['media'] = [];
    $config->set('enabled_entity_types', $enabled)->save();
    $this->assertTrue($logger->hasRecordThatPasses(
      static fn (array $record): bool => ($record['context']['@entity_type_id'] ?? '') === 'media'
        && str_contains($record['message'], "'deleted' field does not belong to Trash"),
      RfcLogLevel::ERROR,
    ));

    // Disabling the entity type must refuse to uninstall the foreign field.
    try {
      $this->getTrashManager()->disableEntityType($this->getEntityTypeManager()->getDefinition('media'));
      $this->fail('Uninstalling a foreign deleted field must throw.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString("does not belong to Trash; refusing to uninstall it", $e->getMessage());
    }
    $field_storage_definitions = \Drupal::service('entity.last_installed_schema.repository')->getLastInstalledFieldStorageDefinitions('media');
    $this->assertArrayHasKey('deleted', $field_storage_definitions);
    $this->assertSame('media', $field_storage_definitions['deleted']->getProvider());
  }

  /**
   * Tests that the storage proxy implements all the required interfaces.
   */
  public function testStorageDecoratorInterfaceCompleteness(): void {
    // Get the expected storage class from the entity type definition.
    $entity_type = $this->getEntityTypeManager()->getDefinition('trash_test_entity');
    $storage_class = $entity_type->getStorageClass();
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');

    // Verify the storage implements the entity-type-specific storage interface.
    $this->assertInstanceOf(TrashTestEntityStorageInterface::class, $storage);

    // Verify custom methods from the entity-type-specific interface work.
    $this->assertEquals('custom_test_value_from_storage', $storage->getCustomTestValue());

    // Verify the storage implements all interfaces of the expected class.
    foreach (class_implements($storage_class) as $interface) {
      $this->assertInstanceOf($interface, $storage);
    }

    // Verify all public methods from the expected class are callable.
    $reflection = new \ReflectionClass($storage_class);
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if (str_starts_with($method->getName(), '__') || $method->isStatic()) {
        continue;
      }
      $this->assertTrue(is_callable([$storage, $method->getName()]));
    }
  }

}
