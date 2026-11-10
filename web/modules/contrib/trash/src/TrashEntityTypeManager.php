<?php

namespace Drupal\trash;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\PhpStorage\PhpStorageFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Provides custom storage handlers for trash-supported entity types.
 */
final class TrashEntityTypeManager implements EntityTypeManagerInterface {

  use DependencySerializationTrait;
  use LoggerChannelTrait;

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line pluginManagerSetsCacheBackend.missingCacheBackend
   */
  public function __construct(
    #[AutowireDecorated]
    protected EntityTypeManagerInterface $inner,
    #[Autowire(param: 'trash.storage_class_hash')]
    protected string $storageClassHash = '',
  ) {}

  /**
   * Contains instantiated storage handlers keyed by entity type ID.
   */
  protected array $storageHandlers = [];

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    $this->inner->clearCachedDefinitions();
    $this->storageHandlers = [];
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE) {
    $this->inner->useCaches($use_caches);
    if (!$use_caches) {
      $this->storageHandlers = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStorage($entity_type_id) {
    return $this->getHandler($entity_type_id, 'storage');
  }

  /**
   * {@inheritdoc}
   */
  public function getHandler($entity_type_id, $handler_type) {
    if ($handler_type !== 'storage') {
      return $this->inner->getHandler($entity_type_id, $handler_type);
    }

    if (!isset($this->storageHandlers[$entity_type_id])) {
      $definition = $this->getDefinition($entity_type_id);
      $class = $definition->getHandlerClass($handler_type);
      if (!$class) {
        throw new InvalidPluginDefinitionException($entity_type_id, sprintf('The "%s" entity type did not specify a %s handler.', $entity_type_id, $handler_type));
      }
      $this->storageHandlers[$entity_type_id] = $this->createHandlerInstance($class, $definition);
    }

    return $this->storageHandlers[$entity_type_id];
  }

  /**
   * {@inheritdoc}
   */
  public function createHandlerInstance($class, ?EntityTypeInterface $definition = NULL) {
    if ($definition !== NULL
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      && \Drupal::service('trash.manager')->isEntityTypeEnabled($definition)
      && is_a($class, SqlContentEntityStorage::class, TRUE)) {
      $class = $this->generateStorageClass($class);
    }

    return $this->inner->createHandlerInstance($class, $definition);
  }

  /**
   * Generates a trash-enabled storage class for an entity type.
   *
   * @param string $original_class
   *   The entity storage class name.
   *
   * @return string
   *   The new entity storage class name.
   */
  protected function generateStorageClass(string $original_class): string {
    $original_class = ltrim($original_class, '\\');
    // The hash changes when the template that produces this class changes, so
    // a new class name is generated and PHP can load it even in a request that
    // already loaded the old one.
    $trash_class = str_replace('\\', '__', $original_class) . '__Trash_' . $this->storageClassHash;

    // If the class is already loaded in this request, use it. This handles
    // scenarios where cache is cleared mid-request and files are deleted, but
    // the class is still in memory.
    if (class_exists($trash_class, FALSE)) {
      return $trash_class;
    }

    $php_storage = PhpStorageFactory::get('trash');

    // Try to load existing file.
    if ($php_storage->load($trash_class . '.php')) {
      return $trash_class;
    }

    // File doesn't exist or is corrupted, use a lock to prevent race conditions
    // when multiple web heads try to generate the file simultaneously.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $lock = \Drupal::lock();
    $lock_name = 'trash_storage:' . $trash_class;

    if ($lock->acquire($lock_name)) {
      try {
        $code = file_get_contents(__DIR__ . '/../templates/TrashStorage.php.twig');
        $code = str_replace(
          ['{{ trash_class }}', '{{ original_class }}'],
          [$trash_class, $original_class],
          $code
        );
        $php_storage->save($trash_class . '.php', $code);
      }
      finally {
        $lock->release($lock_name);
      }
    }
    else {
      // Another process is generating the file, wait for it.
      $lock->wait($lock_name);
    }

    // Try loading the file.
    if ($php_storage->load($trash_class . '.php')) {
      return $trash_class;
    }

    $this->getLogger('trash')->critical('Failed to load generated trash storage class for @class.', [
      '@class' => $original_class,
    ]);

    return $original_class;
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessControlHandler($entity_type_id) {
    return $this->inner->getAccessControlHandler($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getViewBuilder($entity_type_id) {
    return $this->inner->getViewBuilder($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getListBuilder($entity_type_id) {
    return $this->inner->getListBuilder($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormObject($entity_type_id, $operation) {
    return $this->inner->getFormObject($entity_type_id, $operation);
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteProviders($entity_type_id) {
    return $this->inner->getRouteProviders($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function hasHandler($entity_type_id, $handler_type) {
    return $this->inner->hasHandler($entity_type_id, $handler_type);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($entity_type_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($entity_type_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->inner->getInstance($options);
  }

  /**
   * Default EntityTypeManager has some naughty methods not on interface.
   */
  public function getActiveDefinition($entity_type_id) {
    // @phpstan-ignore-next-line method.notFound
    return $this->inner->getActiveDefinition($entity_type_id);
  }

}
