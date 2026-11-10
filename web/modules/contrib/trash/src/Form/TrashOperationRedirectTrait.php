<?php

declare(strict_types=1);

namespace Drupal\trash\Form;

use Drupal\Core\Url;

/**
 * Builds the cancel and fallback URLs of the trash operation forms.
 *
 * @see \Drupal\trash\Plugin\Derivative\TrashLocalTasks::getEntityOperationDefinitions()
 */
trait TrashOperationRedirectTrait {

  /**
   * Returns the URL of the deleted entity, or NULL if it can not be opened.
   */
  protected function getTrashedEntityUrl(): ?Url {
    // A 'destination' from the trash listing overrides this, so it is only
    // reached from the entity's own local tasks.
    $entity = $this->getEntity();
    if (!$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }

    // A deleted entity can only be viewed in the trash context.
    $url = $entity->toUrl('canonical', ['query' => ['in_trash' => TRUE]]);

    // Viewing also needs 'view deleted entities', which restoring and purging
    // do not.
    return $url->access() ? $url : NULL;
  }

  /**
   * Returns the URL of the trash listing of the entity type.
   */
  protected function getTrashListingUrl(): Url {
    // The listing needs 'access trash', which the restore and purge permissions
    // do not imply, so fall back to the front page rather than sending anyone
    // to a 403.
    $url = Url::fromRoute('trash.admin_content_trash_entity_type', [
      'entity_type_id' => $this->getEntity()->getEntityTypeId(),
    ]);

    return $url->access() ? $url : Url::fromRoute('<front>');
  }

}
