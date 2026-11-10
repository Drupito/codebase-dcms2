<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;

/**
 * Base class for Trash kernel tests.
 */
abstract class TrashKernelTestBase extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'filter',
    'node',
    'text',
    'trash',
    'trash_test',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * Whether to install the node entity schema and the article/page bundles.
   *
   * Tests that only exercise trash_test_entity can set this to FALSE to skip
   * the node schema and content type setup.
   */
  protected bool $installNode = TRUE;

  /**
   * Additional entity types to enable for trash, keyed by entity type ID.
   *
   * Subclasses set this (and install the matching schema in
   * self::installAdditionalEntitySchemas()) so that everything is enabled in
   * the single container rebuild performed by self::setUp(). The value is an
   * array of bundle names to enable, or an empty array for all bundles.
   */
  protected array $additionalTrashEntityTypes = [];

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('trash_test_entity');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['filter', 'trash_test']);

    $entity_types = ['trash_test_entity' => []];
    if ($this->installNode) {
      $this->installEntitySchema('node');
      $this->installSchema('node', ['node_access']);
      $this->installConfig(['node']);
      $this->createContentType(['type' => 'article']);
      $this->createContentType(['type' => 'page']);
      $entity_types['node'] = ['article'];
    }

    $this->installAdditionalEntitySchemas();

    // Enable everything in a single pass so only one container rebuild is
    // needed during setup.
    $this->enableEntityTypesForTrash($entity_types + $this->additionalTrashEntityTypes);
  }

  /**
   * Loads an entity in the 'ignored' trash context.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage.
   * @param string $id
   *   The entity ID.
   * @param string|null $langcode
   *   (optional) A translation to return instead of the default one.
   * @param string|null $revision_id
   *   (optional) A specific revision to load instead of the default revision.
   *
   * @return ($storage is \Drupal\Core\Entity\ContentEntityStorageInterface ?
   *   \Drupal\Core\Entity\ContentEntityInterface|null :
   *   \Drupal\Core\Entity\EntityInterface|null) An entity or NULL if the
   *   entity was truly not found.
   */
  protected function forceLoadEntity(EntityStorageInterface $storage, string $id, ?string $langcode = NULL, ?string $revision_id = NULL): EntityInterface|ContentEntityInterface|null {
    $entity = $this->getTrashManager()->executeInTrashContext('ignore', function () use ($storage, $id, $revision_id) {
      if ($revision_id === NULL) {
        return $storage->load($id);
      }
      assert($storage instanceof RevisionableStorageInterface);
      return $storage->loadRevision($revision_id);
    });

    if (NULL !== $langcode && $entity instanceof TranslatableInterface && $entity->language()->getId() !== $langcode) {
      $entity = $entity->getTranslation($langcode);
    }

    return $entity;
  }

  /**
   * Gets the trash manager.
   */
  protected function getTrashManager(): TrashManagerInterface {
    return $this->container->get('trash.manager');
  }

  /**
   * Gets the entity type manager.
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    return $this->container->get('entity_type.manager');
  }

  /**
   * Gets the entity repository.
   */
  protected function getEntityRepository(): EntityRepositoryInterface {
    return $this->container->get('entity.repository');
  }

  /**
   * Enables entity types for trash.
   *
   * @param array $entity_types
   *   An array keyed by entity type ID, with values being an array of bundle
   *   names to enable, or an empty array to enable all bundles. If an array key
   *   is numeric, its value is expected to be an entity type ID for which all
   *   bundles will be enabled. For example:
   *   @code
   *   ['node' => ['article', 'page'], 'path_alias' => [], 'redirect']
   *   @endcode
   */
  protected function enableEntityTypesForTrash(array $entity_types): void {
    $config = \Drupal::configFactory()->getEditable('trash.settings');
    $enabled_entity_types = $config->get('enabled_entity_types');
    foreach ($entity_types as $entity_type_id => $bundles) {
      // Support simple arrays like ['path_alias', 'redirect'].
      if (is_int($entity_type_id)) {
        $entity_type_id = $bundles;
        $bundles = [];
      }
      $enabled_entity_types[$entity_type_id] = $bundles;
    }
    $config->set('enabled_entity_types', $enabled_entity_types);
    $config->save();

    // Rebuild the container so trash handlers are available.
    $this->container->get('kernel')->rebuildContainer();
    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * Disables entity types for trash.
   *
   * @param array $entity_type_ids
   *   An array of entity type IDs to disable.
   */
  protected function disableEntityTypesForTrash(array $entity_type_ids): void {
    $config = \Drupal::configFactory()->getEditable('trash.settings');
    $enabled_entity_types = $config->get('enabled_entity_types');
    foreach ($entity_type_ids as $entity_type_id) {
      unset($enabled_entity_types[$entity_type_id]);
    }
    $config->set('enabled_entity_types', $enabled_entity_types);
    $config->save();

    // Rebuild the container.
    $this->container->get('kernel')->rebuildContainer();
    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * Loads a trashed entity by ID.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The loaded entity, or NULL if not found.
   */
  protected function loadTrashedEntity(string $entity_type_id, int|string $entity_id): ?ContentEntityInterface {
    $entity = $this->getTrashManager()->executeInTrashContext('ignore', fn () =>
      $this->getEntityTypeManager()->getStorage($entity_type_id)->load($entity_id)
    );
    assert($entity === NULL || $entity instanceof ContentEntityInterface);
    return $entity;
  }

  /**
   * Restores a trashed entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   * @param string[] $langcodes
   *   (optional) The translation langcodes to restore. Defaults to all
   *   translations.
   */
  protected function restoreEntity(string $entity_type_id, int|string $entity_id, array $langcodes = []): void {
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($entity_type_id, $entity_id, $langcodes): void {
      if ($entity = $this->getEntityTypeManager()->getStorage($entity_type_id)->load($entity_id)) {
        Trash::restoreEntity($entity, $langcodes);
      }
    });
  }

  /**
   * Permanently deletes a trashed entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   */
  protected function purgeEntity(string $entity_type_id, int|string $entity_id): void {
    $this->getTrashManager()->executeInTrashContext('ignore', function () use ($entity_type_id, $entity_id): void {
      if ($entity = $this->getEntityTypeManager()->getStorage($entity_type_id)->load($entity_id)) {
        $this->getEntityTypeManager()->getStorage($entity_type_id)->delete([$entity]);
      }
    });
  }

  /**
   * Installs the schema for the additional trash-enabled entity types.
   *
   * Installs schema for the types in self::$additionalTrashEntityTypes before
   * the single trash-enabling container rebuild, so the relevant tables exist
   * when the 'deleted' field is installed on them.
   */
  protected function installAdditionalEntitySchemas(): void {}

  /**
   * Creates a permanent file entity at the given public URI.
   *
   * @param string $uri
   *   The file URI.
   * @param string $contents
   *   The file contents.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
   */
  protected function createPublicFile(string $uri, string $contents): FileInterface {
    $file_system = $this->container->get('file_system');
    assert($file_system instanceof FileSystemInterface);
    $dir = $file_system->dirname($uri);
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($uri, $contents);
    $file = File::create(['uri' => $uri, 'uid' => 1]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

}
