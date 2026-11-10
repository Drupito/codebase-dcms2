<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\trash\Handler\DefaultTrashHandler;
use Drupal\trash\Trash;
use Drupal\trash\TrashStorageInterface;
use Drupal\trash_test\Entity\TrashTestEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the crash and data-integrity guards in TrashStorageTrait.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashStorageTraitTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * {@inheritdoc}
   */
  protected bool $installNode = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Restoring a batch skips entities that no longer exist.
   */
  public function testRestoreSkipsPurgedEntity(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);

    $first = TrashTestEntity::create(['label' => 'First']);
    $first->save();
    $second = TrashTestEntity::create(['label' => 'Second']);
    $second->save();
    $storage->delete([$first, $second]);

    // Keep a stale object for the first entity, then purge it.
    $stale_first = $this->forceLoadEntity($storage, $first->id());
    $this->purgeEntity('trash_test_entity', $first->id());

    // The purged entity is skipped and the rest of the batch is restored.
    $second = $this->forceLoadEntity($storage, $second->id());
    $storage->restoreFromTrash([$stale_first, $second]);

    $this->assertNull($storage->load($first->id()));
    $restored = $storage->load($second->id());
    $this->assertNotNull($restored);
    $this->assertFalse(Trash::entityIsDeleted($restored));
  }

  /**
   * The trash hooks carry the affected langcodes and deletion timestamps.
   */
  public function testTrashHooksReceiveTranslationContext(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);
    $request_time = \Drupal::time()->getRequestTime();

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = (string) $entity->id();

    // Soft-delete the German translation, then backdate its timestamp so the
    // two translations end up trashed at different times.
    $entity->removeTranslation('de');
    $entity->save();
    $backdated = $request_time - 1000;
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($storage, $id, $backdated): void {
      $entity = $storage->load($id);
      assert($entity instanceof ContentEntityInterface);
      $entity->getTranslation('de')->set('deleted', $backdated);
      $entity->save();
    });

    \Drupal::keyValue('trash_test')->set('record_trash_hooks', TRUE);

    // The whole-entity delete only reports the newly trashed translation; the
    // already-trashed one keeps its state and is not part of the operation.
    $storage->load($id)->delete();

    // A full restore reports every restored translation, each with its own
    // deletion timestamp.
    $this->restoreEntity('trash_test_entity', $id);

    $calls = \Drupal::keyValue('trash_test')->get('trash_hook_calls', []);
    $this->assertSame([
      'entity_pre_trash_delete',
      'entity_trash_delete',
      'entity_pre_trash_restore',
      'entity_trash_restore',
    ], array_column($calls, 'hook'));
    $this->assertSame(['en'], $calls[0]['context']);
    $this->assertSame(['en'], $calls[1]['context']);
    $this->assertSame(['en', 'de'], $calls[2]['context']);
    $this->assertEquals(['en' => $request_time, 'de' => $backdated], $calls[3]['context']);
  }

  /**
   * A whole-entity delete keeps the deletion time of trashed translations.
   */
  public function testDeleteKeepsExistingTranslationDeletionTime(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);
    $request_time = \Drupal::time()->getRequestTime();

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = $entity->id();

    // Soft-delete only the German translation.
    $entity->removeTranslation('de');
    $entity->save();

    // Backdate the German deletion timestamp so it differs from the request
    // time used by the whole-entity delete below.
    $backdated = $request_time - 1000;
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($storage, $id, $backdated): void {
      $entity = $storage->load($id);
      assert($entity instanceof ContentEntityInterface);
      $entity->getTranslation('de')->set('deleted', $backdated);
      $entity->save();
    });

    // Delete the whole entity; only the live translation gets marked.
    $storage->load($id)->delete();

    $entity = $this->forceLoadEntity($storage, $id);
    $this->assertEquals($request_time, $entity->get('deleted')->value);
    $this->assertEquals($backdated, $entity->getTranslation('de')->get('deleted')->value);

    // Only the deleted translation is marked as affected in the deletion
    // revision.
    $this->assertTrue((bool) $entity->isRevisionTranslationAffected());
    $this->assertFalse((bool) $entity->getTranslation('de')->isRevisionTranslationAffected());
  }

  /**
   * Deleting an already-trashed entity keeps its deletion timestamp.
   */
  public function testRepeatedDeleteKeepsDeletionTime(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);

    $entity = TrashTestEntity::create(['label' => 'Test']);
    $entity->save();
    $id = $entity->id();
    $entity->delete();

    // Backdate the deletion timestamp so it differs from the request time a
    // repeated delete would mark.
    $backdated = \Drupal::time()->getRequestTime() - 1000;
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($storage, $id, $backdated): void {
      $entity = $storage->loadUnchanged($id);
      assert($entity instanceof ContentEntityInterface);
      $entity->set('deleted', $backdated);
      $entity->save();
    });

    // Delete the trashed entity again; it must be left untouched, keeping
    // its auto-purge window and the timestamp seen by restore integrations.
    $trashed = $this->loadTrashedEntity('trash_test_entity', $id);
    $revision_id = $trashed->getRevisionId();
    $storage->delete([$trashed]);

    $reloaded = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertEquals($backdated, $reloaded->get('deleted')->value);
    $this->assertEquals($revision_id, $reloaded->getRevisionId());

    // Restore the entity, then restore it again: restoring an entity that is
    // not trashed is a no-op instead of handing a NULL deleted timestamp to
    // the trash handlers.
    $storage->restoreFromTrash([$reloaded]);
    $live = $storage->loadUnchanged($id);
    $this->assertFalse(Trash::entityIsDeleted($live));
    $storage->restoreFromTrash([$live]);
    $this->assertFalse(Trash::entityIsDeleted($storage->loadUnchanged($id)));

    // Delete the restored entity through the stale object that still has
    // the old deleted timestamp: the stored state is live, so the delete must
    // trash the entity again instead of silently skipping it.
    $storage->delete([$trashed]);
    $this->assertNull($storage->load($id));
    $re_trashed = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertEquals(\Drupal::time()->getRequestTime(), $re_trashed->get('deleted')->value);
  }

  /**
   * A failed restore rolls back the transaction and resets entity caches.
   */
  public function testRestoreRollbackResetsCaches(): void {
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    assert($storage instanceof TrashStorageInterface);

    $entity = TrashTestEntity::create(['label' => 'Test']);
    $entity->save();
    $id = $entity->id();
    $entity->delete();

    // Swap in a handler that fails after the restore save, once the entity
    // caches hold the uncommitted restored state.
    $handler = new class() extends DefaultTrashHandler {

      /**
       * The throwable postTrashRestore() throws after the restore save.
       */
      public \Throwable $failure;

      /**
       * {@inheritdoc}
       */
      public function postTrashRestore(EntityInterface $entity, array $deleted_timestamps): void {
        // Put the uncommitted restored state into the entity caches.
        $this->entityTypeManager->getStorage($entity->getEntityTypeId())->load($entity->id());
        throw $this->failure;
      }

    };
    $handler->failure = new \RuntimeException('Restore failed after save.');
    $handler->setEntityTypeId('trash_test_entity')
      ->setEntityTypeManager($this->getEntityTypeManager())
      ->setTrashManager($this->getTrashManager());
    $this->getTrashManager()->addHandler($handler, 'trash_test_entity');

    $entity = $this->forceLoadEntity($storage, $id);
    try {
      $storage->restoreFromTrash([$entity]);
      $this->fail('The handler exception must be rethrown.');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    // The rollback resets the entity caches, so the entity is still trashed
    // everywhere: in the persistent cache as well as in SQL queries.
    $this->assertFalse(\Drupal::cache('entity')->get("values:trash_test_entity:$id"));
    $this->assertNull($storage->load($id));
    $reloaded = $this->forceLoadEntity($storage, $id);
    $this->assertTrue(Trash::entityIsDeleted($reloaded));

    // An \Error (e.g. a TypeError in a handler or a hook implementation) must
    // roll back the same way; catching only \Exception would let the
    // transaction commit the half-restored state on stack unwind.
    $handler->failure = new \Error('Restore failed after save.');
    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    try {
      $storage->restoreFromTrash([$entity]);
      $this->fail('The handler error must be rethrown.');
    }
    catch (\Error) {
      // Expected.
    }

    $this->assertFalse(\Drupal::cache('entity')->get("values:trash_test_entity:$id"));
    $this->assertNull($storage->load($id));
    $reloaded = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertTrue(Trash::entityIsDeleted($reloaded));
  }

}
