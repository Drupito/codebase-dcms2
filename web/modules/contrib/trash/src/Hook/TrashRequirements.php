<?php

declare(strict_types=1);

namespace Drupal\trash\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\trash\Form\TrashSettingsForm;
use Drupal\trash\TrashManagerInterface;

/**
 * Requirements for the Trash module.
 */
class TrashRequirements {

  use StringTranslationTrait;

  public function __construct(
    protected TrashManagerInterface $trashManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];

    $unsupported_entity_types = array_intersect(
      $this->trashManager->getEnabledEntityTypes(),
      TrashSettingsForm::getUnsupportedEntityTypes(),
    );
    if ($unsupported_entity_types) {
      $labels = array_map(
        fn (string $entity_type_id): string => (string) $this->entityTypeManager->getDefinition($entity_type_id)->getLabel(),
        $unsupported_entity_types,
      );
      $requirements['trash_disallowed_entity_types'] = [
        'title' => $this->t('Trash'),
        'value' => $this->t('Unsupported entity types enabled'),
        'severity' => RequirementSeverity::Warning,
        'description' => $this->t('Trash is enabled for the following entity types that are not supported and can cause errors: %entity_types.<br />Disable them on the <a href=":url">Trash settings</a> page.', [
          '%entity_types' => implode(', ', $labels),
          ':url' => Url::fromRoute('trash.settings.form')->toString(),
        ]),
      ];
    }

    return $requirements;
  }

}
