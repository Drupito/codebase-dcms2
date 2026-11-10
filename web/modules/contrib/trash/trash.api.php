<?php

/**
 * @file
 * Hooks and documentation related to Trash.
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\views\ViewExecutable;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Act before entity soft-deletion.
 *
 * This hook also fires when individual translations are soft-deleted, for
 * example when a translation is removed from an entity; $langcodes says
 * which translations are affected.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that is about to be soft-deleted.
 * @param string[] $langcodes
 *   The langcodes of the translations that are about to be marked as deleted.
 *   Translations that are already trashed are not included.
 *
 * @ingroup entity_crud
 * @see hook_ENTITY_TYPE_pre_trash_delete()
 */
function hook_entity_pre_trash_delete(EntityInterface $entity, array $langcodes) {
}

/**
 * Act before entity soft-deletion of a particular entity type.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that is about to be soft-deleted.
 * @param string[] $langcodes
 *   The langcodes of the translations that are about to be marked as deleted.
 *   Translations that are already trashed are not included.
 *
 * @ingroup entity_crud
 * @see hook_entity_pre_trash_delete()
 */
function hook_ENTITY_TYPE_pre_trash_delete(EntityInterface $entity, array $langcodes) {
}

/**
 * Respond to entity soft-deletion.
 *
 * This hook runs once the entity has been soft-deleted from the storage. It
 * also fires when individual translations are soft-deleted, for example when
 * a translation is removed from an entity; $langcodes says which translations
 * are affected.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that has been soft-deleted.
 * @param string[] $langcodes
 *   The langcodes of the translations that were marked as deleted.
 *   Translations that were already trashed are not included.
 *
 * @ingroup entity_crud
 * @see hook_ENTITY_TYPE_trash_delete()
 */
function hook_entity_trash_delete(EntityInterface $entity, array $langcodes) {
}

/**
 * Respond to entity soft-deletion of a particular type.
 *
 * This hook runs once the entity has been soft-deleted from the storage.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that has been soft-deleted.
 * @param string[] $langcodes
 *   The langcodes of the translations that were marked as deleted.
 *   Translations that were already trashed are not included.
 *
 * @ingroup entity_crud
 * @see hook_entity_trash_delete()
 */
function hook_ENTITY_TYPE_trash_delete(EntityInterface $entity, array $langcodes) {
}

/**
 * Act before restoring an entity from trash.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that is about to be restored.
 * @param string[] $langcodes
 *   The langcodes of the translations that are about to be restored.
 *
 * @ingroup entity_crud
 * @see hook_ENTITY_TYPE_pre_trash_restore()
 */
function hook_entity_pre_trash_restore(EntityInterface $entity, array $langcodes) {
}

/**
 * Act before restoring an entity of a particular type from trash.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that is about to be restored.
 * @param string[] $langcodes
 *   The langcodes of the translations that are about to be restored.
 *
 * @ingroup entity_crud
 * @see hook_entity_pre_trash_restore()
 */
function hook_ENTITY_TYPE_pre_trash_restore(EntityInterface $entity, array $langcodes) {
}

/**
 * Respond to restoring an entity from trash.
 *
 * This hook runs once the entity has been restored from trash.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that has been restored.
 * @param array $deleted_timestamps
 *   The deletion timestamps of the restored translations, keyed by langcode.
 *   Translations can be trashed at different times, so the values may differ.
 *
 * @ingroup entity_crud
 * @see hook_ENTITY_TYPE_trash_restore()
 */
function hook_entity_trash_restore(EntityInterface $entity, array $deleted_timestamps) {
}

/**
 * Respond to restoring an entity of a particular type from trash.
 *
 * This hook runs once the entity has been restored from trash.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity object for the entity that has been restored.
 * @param array $deleted_timestamps
 *   The deletion timestamps of the restored translations, keyed by langcode.
 *   Translations can be trashed at different times, so the values may differ.
 *
 * @ingroup entity_crud
 * @see hook_entity_trash_restore()
 */
function hook_ENTITY_TYPE_trash_restore(EntityInterface $entity, array $deleted_timestamps) {
}

/**
 * Alter the dynamically built Trash view for an entity type.
 *
 * @param \Drupal\views\ViewExecutable $view
 *   The View executable being built.
 * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
 *   The entity type for which the Trash view is being built.
 * @param bool $export
 *   Whether the generated view will be exported as an entity.
 *
 * @see \Drupal\trash\TrashViewBuilder
 */
function hook_trash_views_build(ViewExecutable $view, EntityTypeInterface $entity_type, bool $export) {
  // ID field.
  $id_key = $entity_type->getKey('id');
  if ($id_key) {
    $base_table = $entity_type->getDataTable() ?: $entity_type->getBaseTable();
    $view->addHandler('default', 'field', $base_table, $id_key);

    $label_key = $entity_type->getKey('label');
    if ($label_key) {
      $label_field = $view->getHandler('default', 'field', $label_key);
      if ($label_field) {
        // Don't show the entity ID since it is now a dedicated column.
        $label_field['settings']['show_entity_id'] = FALSE;
        $view->setHandler('default', 'field', $label_key, $label_field);
      }
    }

    $display = $view->getDisplay();
    $fields = $display->getOption('fields');

    // Add the ID field before the label column.
    if ($label_key) {
      $index = (int) array_search($label_key, array_keys($fields), TRUE);
    }
    else {
      $index = isset($fields[$entity_type->id() . '_bulk_form']) ? 1 : 0;
    }
    $fields = array_merge(array_slice($fields, 0, $index), [
      $id_key => $fields[$id_key],
    ], array_slice($fields, $index));
    $display->setOption('fields', $fields);

    // Make the ID field sortable.
    $style = $display->getOption('style');
    $style['options']['columns'][$id_key] = $id_key;
    $style['options']['info'][$id_key] = [
      'sortable' => TRUE,
      'default_sort_order' => 'asc',
    ];
    $display->setOption('style', $style);
  }
}

/**
 * @} End of "addtogroup hooks".
 */
