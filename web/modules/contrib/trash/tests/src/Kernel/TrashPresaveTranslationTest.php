<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\trash_test\Entity\TrashTestEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity presave handling of removed translations.
 *
 * Covers the mixed "remove translation + other changes" save, the baseline
 * used when a pending revision is being saved, and the guard against a
 * missing storage baseline.
 *
 * @see \Drupal\trash\Hook\TrashEntityHooks::entityPresave()
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashPresaveTranslationTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected bool $installNode = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * A save that removes a translation and changes other values succeeds.
   *
   * Also covers that soft-deleting a translation does not run
   * hook_entity_translation_create(), since nothing is being created, and that
   * an implementation of it therefore cannot replace the preserved values.
   *
   * @see https://www.drupal.org/project/trash/issues/3613615
   */
  public function testMixedSaveKeepsChangesAndSoftDeletesTranslation(): void {
    \Drupal::keyValue('trash_test')->set('record_translation_create', TRUE);

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = (string) $entity->id();
    $revision_id = $entity->getRevisionId();

    // Adding the German translation was a real creation, so the hook ran.
    $this->assertSame(['de'], \Drupal::keyValue('trash_test')->get('translation_create_calls'));

    // Arm the replacement only now, so it cannot touch the values that the
    // soft-delete is supposed to preserve.
    \Drupal::keyValue('trash_test')->set('translation_create_label', 'Replaced by hook');

    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $storage->load($id);
    $entity->set('label', 'EN updated');
    $entity->removeTranslation('de');
    $entity->save();

    // Putting the removed translation back is not a creation, so the hook did
    // not run again and had no chance to replace anything.
    $this->assertSame(['de'], \Drupal::keyValue('trash_test')->get('translation_create_calls'));

    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $this->assertSame('EN updated', $entity->label());
    $this->assertTrue($entity->hasTranslation('de'));
    $de = $entity->getTranslation('de');
    $this->assertSame('DE', $de->label());
    $this->assertNotEmpty($de->get('deleted')->value);
    $this->assertTrue((bool) $de->isRevisionTranslationAffected());
    $this->assertTrue((bool) $entity->isRevisionTranslationAffected());
    $this->assertNotSame($revision_id, $entity->getRevisionId());

    // The replacement does reach a real translation creation, so the preserved
    // label above is not passing by accident.
    $other = TrashTestEntity::create(['label' => 'EN']);
    $other->addTranslation('de', ['label' => 'DE']);
    $this->assertSame('Replaced by hook', $other->getTranslation('de')->label());
  }

  /**
   * Removing a translation from a pending revision keeps its pending values.
   */
  public function testPendingRevisionRemovalUsesPendingValues(): void {
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE default']);
    $entity->save();
    $id = (string) $entity->id();
    $default_revision_id = $entity->getRevisionId();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->createRevision($entity, FALSE);
    $pending->getTranslation('de')->set('label', 'DE pending');
    $pending->save();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->loadRevision($pending->getRevisionId());
    $pending->removeTranslation('de');
    $pending->save();

    // The re-added deleted translation has the pending revision's values,
    // not the default revision's.
    $latest_revision_id = $storage->getLatestRevisionId($id);
    $latest = $this->forceLoadEntity($storage, $id, NULL, (string) $latest_revision_id);
    $this->assertTrue($latest->hasTranslation('de'));
    $de = $latest->getTranslation('de');
    $this->assertSame('DE pending', $de->label());
    $this->assertNotEmpty($de->get('deleted')->value);

    // The default revision is untouched and its translation stays live.
    $default = $this->forceLoadEntity($storage, $id, NULL, (string) $default_revision_id);
    $this->assertSame('DE default', $default->getTranslation('de')->label());
    $this->assertEmpty($default->getTranslation('de')->get('deleted')->value);
  }

  /**
   * Removing a translation that exists only in a pending revision works.
   */
  public function testPendingRevisionOnlyTranslationRemoval(): void {
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->save();
    $id = (string) $entity->id();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->createRevision($entity, FALSE);
    $pending->addTranslation('de', ['label' => 'DE pending only']);
    $pending->save();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->loadRevision($pending->getRevisionId());
    $pending->removeTranslation('de');
    $pending->save();

    $latest = $this->forceLoadEntity($storage, $id, NULL, (string) $storage->getLatestRevisionId($id));
    $this->assertTrue($latest->hasTranslation('de'));
    $de = $latest->getTranslation('de');
    $this->assertSame('DE pending only', $de->label());
    $this->assertNotEmpty($de->get('deleted')->value);
  }

  /**
   * A live save that soft-deletes a translation fires the trash delete hooks.
   */
  public function testTranslationTrashEventsFire(): void {
    \Drupal::keyValue('trash_test')->set('record_trash_hooks', TRUE);

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = (string) $entity->id();

    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $storage->load($id);
    $entity->removeTranslation('de');
    $entity->save();

    $calls = \Drupal::keyValue('trash_test')->get('trash_hook_calls', []);
    $this->assertCount(2, $calls);
    $this->assertSame('entity_pre_trash_delete', $calls[0]['hook']);
    $this->assertSame('entity_trash_delete', $calls[1]['hook']);
    foreach ($calls as $call) {
      $this->assertSame('trash_test_entity', $call['entity_type_id']);
      $this->assertSame($id, (string) $call['entity_id']);
      $this->assertSame(['de'], $call['context']);
    }
  }

  /**
   * Removing a translation from a pending revision fires no trash events.
   */
  public function testPendingRevisionRemovalFiresNoEvents(): void {
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
    $storage = $this->getEntityTypeManager()->getStorage('trash_test_entity');

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();

    \Drupal::keyValue('trash_test')->set('record_trash_hooks', TRUE);

    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->createRevision($entity, FALSE);
    $pending->save();
    /** @var \Drupal\Core\Entity\ContentEntityInterface $pending */
    $pending = $storage->loadRevision($pending->getRevisionId());
    $pending->removeTranslation('de');
    $pending->save();

    // The soft-delete only exists in a pending revision, so it is not live
    // and integrations must not react to it.
    $this->assertSame([], \Drupal::keyValue('trash_test')->get('trash_hook_calls', []));
  }

  /**
   * Saving a stale object after a concurrent purge must not fatal.
   */
  public function testSaveAfterConcurrentPurge(): void {
    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('de', ['label' => 'DE']);
    $entity->save();
    $id = (string) $entity->id();

    // Hard-delete the stored entity behind the loaded object's back.
    $this->purgeEntity('trash_test_entity', $id);

    // With no stored baseline the presave hook has nothing to preserve and
    // must step aside. The storage layer is free to reject the write with an
    // exception, but a PHP \Error would escape the entity save and fail this
    // test.
    $entity->removeTranslation('de');
    try {
      $entity->save();
    }
    catch (EntityStorageException) {
      // An exception from the storage layer is acceptable.
    }
    $this->assertNull($this->loadTrashedEntity('trash_test_entity', $id));
  }

}
