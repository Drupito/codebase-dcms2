<?php

namespace Drupal\trash\ParamConverter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\ParamConverter\EntityConverter;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for upcasting entity IDs to full objects.
 *
 * Identical to the core EntityConverter, except that it gets the content
 * language from the route's language parameter if available, and then falls
 * back to the UI language or the entity's default language.
 */
class TrashEntityConverter extends EntityConverter {

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The upcasted entity, or NULL if it could not be loaded.
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (!isset($defaults['language']) || !$defaults['language'] instanceof LanguageInterface) {
      return parent::convert($value, $definition, $name, $defaults);
    }

    $entity_type_id = $this->getEntityTypeFromDefaults($definition, $name, $defaults);
    $contexts = ['langcode' => $defaults['language']->getId()];

    // If the entity type is revisionable and the parameter has the
    // "load_latest_revision" flag, load the active variant.
    if (!empty($definition['load_latest_revision'])) {
      $entity = $this->entityRepository->getActive($entity_type_id, $value, $contexts);

      if (
        !empty($definition['bundle']) &&
        $entity instanceof EntityInterface &&
        !in_array($entity->bundle(), $definition['bundle'], TRUE)
      ) {
        return NULL;
      }
      return $entity;
    }

    $contexts += ['operation' => 'entity_upcast'];
    $entity = $this->entityRepository->getCanonical($entity_type_id, $value, $contexts);

    if (
      !empty($definition['bundle']) &&
      $entity instanceof EntityInterface &&
      !in_array($entity->bundle(), $definition['bundle'], TRUE)
    ) {
      return NULL;
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return $route->getOption('_trash_route') && parent::applies($definition, $name, $route);
  }

}
