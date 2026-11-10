<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\trash\Handler\TrashHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides the Trash manager.
 */
class TrashManager implements TrashManagerInterface {

  use DependencySerializationTrait;

  /**
   * One of 'active', 'inactive' or 'ignore'.
   *
   * @var string
   */
  protected $trashContext = 'active';

  /**
   * Trash handlers, keyed by entity type ID.
   *
   * @var array<string, \Drupal\trash\Handler\TrashHandlerInterface>
   */
  protected array $handlers = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'entity.memory_cache')]
    protected CacheTagsInvalidatorInterface $entityMemoryCache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isEntityTypeSupported(EntityTypeInterface $entity_type): bool {
    return is_a($entity_type->getStorageClass(), SqlContentEntityStorage::class, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isEntityTypeEnabled(EntityTypeInterface|string $entity_type, ?string $bundle = NULL): bool {
    $entity_type_id = $entity_type instanceof EntityTypeInterface ? $entity_type->id() : $entity_type;
    $enabled_entity_types = $this->configFactory->get('trash.settings')->get('enabled_entity_types') ?? [];
    if (!isset($enabled_entity_types[$entity_type_id])) {
      return FALSE;
    }
    elseif ($enabled_entity_types[$entity_type_id] === []) {
      return TRUE;
    }
    elseif ($bundle === NULL || in_array($bundle, $enabled_entity_types[$entity_type_id], TRUE)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnabledEntityTypes(): array {
    return array_keys($this->configFactory->get('trash.settings')->get('enabled_entity_types') ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function enableEntityType(EntityTypeInterface $entity_type): void {
    $field_storage_definitions = $this->getEntityLastInstalledSchemaRepository()->getLastInstalledFieldStorageDefinitions($entity_type->id());

    if (!$this->isEntityTypeSupported($entity_type)) {
      throw new \InvalidArgumentException("Trash integration can not be enabled for the {$entity_type->id()} entity type.");
    }

    if (isset($field_storage_definitions['deleted'])) {
      if ($field_storage_definitions['deleted']->getProvider() !== 'trash') {
        throw new \InvalidArgumentException("The {$entity_type->id()} entity type already has a 'deleted' field.");
      }
      else {
        throw new \InvalidArgumentException("Trash integration is already enabled for the {$entity_type->id()} entity type.");
      }
    }

    $this->getEntityDefinitionUpdateManager()->installFieldStorageDefinition('deleted', $entity_type->id(), 'trash', $this->getDeletedFieldDefinition());
  }

  /**
   * {@inheritdoc}
   */
  public function disableEntityType(EntityTypeInterface $entity_type): void {
    $field_storage_definitions = $this->getEntityLastInstalledSchemaRepository()->getLastInstalledFieldStorageDefinitions($entity_type->id());

    if (isset($field_storage_definitions['deleted'])) {
      if ($field_storage_definitions['deleted']->getProvider() !== 'trash') {
        throw new \InvalidArgumentException("The {$entity_type->id()} entity type's 'deleted' field does not belong to Trash; refusing to uninstall it.");
      }
      $this->getEntityDefinitionUpdateManager()->uninstallFieldStorageDefinition($field_storage_definitions['deleted']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function shouldAlterQueries(): bool {
    $trash_context = $this->trashContext;
    return $trash_context !== 'ignore';
  }

  /**
   * {@inheritdoc}
   */
  public function getTrashContext(): string {
    return $this->trashContext;
  }

  /**
   * {@inheritdoc}
   */
  public function setTrashContext(string $context): static {
    assert(in_array($context, ['active', 'inactive', 'ignore'], TRUE));

    if ($this->trashContext !== $context) {
      $this->trashContext = $context;

      // Clear the static entity cache for enabled entity types.
      $cache_tags_to_invalidate = [];
      foreach ($this->getEnabledEntityTypes() as $entity_type_id) {
        $cache_tags_to_invalidate[] = 'entity.memory_cache:' . $entity_type_id;
      }
      $this->entityMemoryCache->invalidateTags($cache_tags_to_invalidate);
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function executeInTrashContext($context, callable $function): mixed {
    $previous = $this->trashContext;
    $this->setTrashContext($context);

    try {
      return $function();
    }
    finally {
      $this->setTrashContext($previous);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addHandler(TrashHandlerInterface $handler, string $entity_type_id): void {
    $this->handlers[$entity_type_id] = $handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getHandler(string $entity_type_id): ?TrashHandlerInterface {
    return $this->handlers[$entity_type_id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeletedFieldDefinition(): BaseFieldDefinition {
    $field = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Deleted'))
      ->setDescription(new TranslatableMarkup('Time when the item got deleted.'))
      ->setInternal(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);

    // Set indexes via reflection since BaseFieldDefinition lacks setIndexes().
    $reflection = new \ReflectionProperty(BaseFieldDefinition::class, 'indexes');
    $reflection->setValue($field, ['value' => ['value']]);

    return $field;
  }

  /**
   * Gets the entity definition update manager.
   */
  protected function getEntityDefinitionUpdateManager(): EntityDefinitionUpdateManagerInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('entity.definition_update_manager');
  }

  /**
   * Gets the entity last installed schema repository.
   */
  protected function getEntityLastInstalledSchemaRepository(): EntityLastInstalledSchemaRepositoryInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('entity.last_installed_schema.repository');
  }

}
