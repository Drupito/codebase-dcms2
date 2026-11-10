<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides dynamic permissions for trash-enabled entity types.
 */
class TrashPermissions implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrashManagerInterface $trashManager,
  ) {}

  /**
   * Returns an array of trash-able entity type permissions.
   *
   * @return array
   *   The entity type permissions.
   *
   * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  public function entityTypePermissions(): array {
    $perms = [];
    $entity_types = $this->entityTypeManager->getDefinitions();
    // Generate media permissions for all trash-able entity types.
    foreach ($this->trashManager->getEnabledEntityTypes() as $entity_type_id) {
      if (!isset($entity_types[$entity_type_id])) {
        continue;
      }

      $definition = $entity_types[$entity_type_id];
      $perms += [
        "restore $entity_type_id entities" => [
          'title' => $this->t('Restore %type entities from the trash bin', [
            '%type' => $definition->getLabel(),
          ]),
          'description' => $this->t('Users with this permission will be able to restore entities from the trash bin.'),
        ],
        "purge $entity_type_id entities" => [
          'title' => $this->t('Purge %type entities from the trash bin', [
            '%type' => $definition->getLabel(),
          ]),
          'description' => $this->t('Users with this permission will be able to permanently delete (purge) trashed entities from the system.'),
        ],
      ];

      // The per-translation permissions only make sense for translatable types.
      if ($definition->isTranslatable()) {
        $perms += [
          "restore $entity_type_id translations" => [
            'title' => $this->t('Restore %type translations from the trash bin', [
              '%type' => $definition->getLabel(),
            ]),
            'description' => $this->t('Users with this permission will only be able to restore translations from the trash bin.'),
          ],
          "purge $entity_type_id translations" => [
            'title' => $this->t('Purge %type translations from the trash bin', [
              '%type' => $definition->getLabel(),
            ]),
            'description' => $this->t('Users with this permission will only be able to permanently delete (purge) trashed translations from the system.'),
          ],
        ];
      }
    }
    return $perms;
  }

}
