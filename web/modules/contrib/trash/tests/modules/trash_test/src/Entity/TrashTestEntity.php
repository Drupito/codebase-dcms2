<?php

declare(strict_types=1);

namespace Drupal\trash_test\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\trash_test\TrashTestEntityListBuilder;
use Drupal\trash_test\TrashTestEntityStorage;
use Drupal\views\EntityViewsData;

/**
 * Provides a trash test entity.
 */
#[ContentEntityType(
  id: 'trash_test_entity',
  label: new TranslatableMarkup('Trash test'),
  label_collection: new TranslatableMarkup('Trash test'),
  label_singular: new TranslatableMarkup('Trash test entity'),
  label_plural: new TranslatableMarkup('Trash test entities'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision',
    'label' => 'label',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  handlers: [
    'storage' => TrashTestEntityStorage::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
    'list_builder' => TrashTestEntityListBuilder::class,
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'collection' => '/admin/content/trash_test',
    'canonical' => '/admin/content/trash_test/{trash_test_entity}',
    'add-form' => '/admin/content/trash_test/add',
    'edit-form' => '/admin/content/trash_test/{trash_test_entity}/edit',
    'delete-form' => '/admin/content/trash_test/{trash_test_entity}/delete',
    'delete-multiple-form' => '/admin/content/trash_test/delete',
    'revision' => '/admin/content/trash_test/{trash_test_entity}/revisions/{trash_test_entity_revision}/view',
  ],
  admin_permission: 'administer trash_test',
  base_table: 'trash_test',
  data_table: 'trash_test_field_data',
  revision_table: 'trash_test_revision',
  revision_data_table: 'trash_test_field_revision',
  translatable: TRUE,
  show_revision_ui: TRUE,
  label_count: [
    'singular' => '@count trash test entity',
    'plural' => '@count trash test entities',
  ],
  field_ui_base_route: 'entity.trash_test_entity.collection',
  common_reference_target: TRUE,
)]
class TrashTestEntity extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['reference'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Reference'))
      ->setDescription(new TranslatableMarkup('Reference to another TrashTestEntity.'))
      ->setSetting('target_type', 'trash_test_entity')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setRequired(FALSE);

    $fields['required_field'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Required field'))
      ->setDescription(new TranslatableMarkup('A required field for testing validation filtering during restore.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDefaultValue('required!')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['unique_code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Unique code'))
      ->setDescription(new TranslatableMarkup('A unique code for testing unique field constraints.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
