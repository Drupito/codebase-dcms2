<?php

declare(strict_types=1);

namespace Drupal\trash\Handler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\trash\TrashManagerInterface;

/**
 * Provides an interface for trash handlers.
 */
interface TrashHandlerInterface {

  /**
   * Acts before an entity is soft-deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param string[] $langcodes
   *   The langcodes of the translations that are about to be marked as
   *   deleted. Translations that are already trashed are not included.
   */
  public function preTrashDelete(EntityInterface $entity, array $langcodes): void;

  /**
   * Acts after an entity is soft-deleted.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param string[] $langcodes
   *   The langcodes of the translations that were marked as deleted.
   *   Translations that were already trashed are not included.
   */
  public function postTrashDelete(EntityInterface $entity, array $langcodes): void;

  /**
   * Validates that an entity can be restored.
   *
   * This method performs validation checks (e.g., unique field violations,
   * conflicting aliases) without modifying the entity. It can be called
   * during form validation to provide early feedback to users.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to validate.
   *
   * @throws \Drupal\trash\Exception\UnrestorableEntityException
   *   Thrown when the entity cannot be restored.
   */
  public function validateRestore(EntityInterface $entity): void;

  /**
   * Acts before an entity is restored.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param string[] $langcodes
   *   The langcodes of the translations that are about to be restored.
   *
   * @throws \Drupal\trash\Exception\UnrestorableEntityException
   *   Thrown when an entity can not be restored from Trash.
   */
  public function preTrashRestore(EntityInterface $entity, array $langcodes): void;

  /**
   * Acts after an entity is restored.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity object.
   * @param array<string, int|string> $deleted_timestamps
   *   The deletion timestamps of the restored translations, keyed by
   *   langcode. Translations can be trashed at different times, so the
   *   values may differ.
   */
  public function postTrashRestore(EntityInterface $entity, array $deleted_timestamps): void;

  /**
   * Alters the entity delete form to provide additional information if needed.
   *
   * @param array $form
   *   The entity form to be altered.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param bool $multiple
   *   (optional) Whether multiple entities are being deleted by the form.
   *   Defaults to FALSE.
   */
  public function deleteFormAlter(array &$form, FormStateInterface $form_state, bool $multiple = FALSE): void;

  /**
   * Alters the entity restore form to provide additional information if needed.
   *
   * @param array $form
   *   The entity form to be altered.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function restoreFormAlter(array &$form, FormStateInterface $form_state): void;

  /**
   * Alters the entity purge form to provide additional information if needed.
   *
   * @param array $form
   *   The entity form to be altered.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function purgeFormAlter(array &$form, FormStateInterface $form_state): void;

  /**
   * Sets the ID of the entity type managed by this handler.
   */
  public function setEntityTypeId(string $entity_type_id): static;

  /**
   * Sets the entity type manager service.
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager): static;

  /**
   * Sets the trash manager service.
   */
  public function setTrashManager(TrashManagerInterface $trash_manager): static;

}
