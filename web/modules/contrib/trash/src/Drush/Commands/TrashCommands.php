<?php

declare(strict_types=1);

namespace Drupal\trash\Drush\Commands;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\trash\TrashCliActions;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;
use Drush\Exceptions\UserAbortException;
use Drush\Utils\StringUtils;
use Symfony\Component\Console\Input\Input;

/**
 * Drush commands for the Trash module.
 */
final class TrashCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected TrashCliActions $cliActions,
  ) {
    parent::__construct();
  }

  /**
   * Restores trashed entities.
   */
  #[CLI\Command(name: 'trash:restore', aliases: ['tr'])]
  #[CLI\Argument(name: 'entity_type_id', description: 'The entity type to restore.')]
  #[CLI\Argument(name: 'entity_ids', description: 'A comma-separated list of entity IDs to restore.')]
  #[CLI\Option(name: 'all', description: 'Restore data for all entity types.')]
  public function restore(?string $entity_type_id = NULL, $entity_ids = NULL, array $options = ['all' => FALSE]): void {
    if (!$options['all']) {
      $this->validateEntityTypeId($entity_type_id);
    }
    $entity_ids = StringUtils::csvToArray($entity_ids);
    $this->getConfirmation('restore', $entity_type_id, $entity_ids, $options);

    if ($options['all']) {
      $entity_type_ids = $this->cliActions->getEnabledEntityTypes();
      $entity_ids = NULL;
    }
    else {
      $entity_type_ids = [$entity_type_id];
    }

    $count = $this->cliActions->performOperation($this->io(), 'restore', $entity_type_ids, $entity_ids);

    $this->io()->success($this->cliActions->resultMessage('restore', $count));
  }

  /**
   * Purges trashed entities.
   */
  #[CLI\Command(name: 'trash:purge', aliases: ['tp'])]
  #[CLI\Argument(name: 'entity_type_id', description: 'The entity type to purge.')]
  #[CLI\Argument(name: 'entity_ids', description: 'A comma-separated list of entity IDs to purge.')]
  #[CLI\Option(name: 'all', description: 'Purge data for all entity types.')]
  public function purge(?string $entity_type_id = NULL, $entity_ids = NULL, array $options = ['all' => FALSE]): void {
    if (!$options['all']) {
      $this->validateEntityTypeId($entity_type_id);
    }
    $entity_ids = StringUtils::csvToArray($entity_ids);
    $this->getConfirmation('purge', $entity_type_id, $entity_ids, $options);

    if ($options['all']) {
      $entity_type_ids = $this->cliActions->getEnabledEntityTypes();
      $entity_ids = NULL;
    }
    else {
      $entity_type_ids = [$entity_type_id];
    }

    $count = $this->cliActions->performOperation($this->io(), 'purge', $entity_type_ids, $entity_ids);

    $this->io()->success($this->cliActions->resultMessage('purge', $count));
  }

  /**
   * Exports the Trash overview page views.
   *
   * This allows site builders to customize the default using Views UI.
   */
  #[CLI\Command(name: 'trash:export-views', aliases: ['tev'])]
  #[CLI\Argument(name: 'entity_type_id', description: 'The entity type to export the Trash overview page view for.')]
  #[CLI\Option(name: 'all', description: 'Export the Trash overview page view for all Trash entity types.')]
  #[CLI\Option(name: 'overwrite', description: 'Overwrite any existing views.')]
  public function exportViews(
    ?string $entity_type_id = NULL,
    array $options = [
      'all' => FALSE,
      'overwrite' => FALSE,
    ],
  ): void {
    $enabled_entity_type_ids = $this->cliActions->getEnabledEntityTypes();
    if (!$enabled_entity_type_ids) {
      throw new UserAbortException($this->cliActions->noEntityTypesEnabledMessage());
    }
    $exportable_entity_type_ids = $this->cliActions->getExportableEntityTypes();
    if (!$exportable_entity_type_ids) {
      throw new UserAbortException($this->cliActions->noExportableEntityTypesMessage());
    }
    if ($options['all']) {
      // Pass every enabled entity type so that the ones without Views
      // integration are reported as skipped instead of dropped silently.
      $entity_type_ids = $enabled_entity_type_ids;
      $confirmation_message = $this->cliActions->exportConfirmationQuestion(TRUE, NULL);
    }
    else {
      // Say why a trash-enabled entity type cannot be exported, instead of
      // falling through to the selection prompt.
      if ($entity_type_id !== NULL && in_array($entity_type_id, $enabled_entity_type_ids, TRUE) && !in_array($entity_type_id, $exportable_entity_type_ids, TRUE)) {
        throw new UserAbortException($this->cliActions->notExportableMessage($entity_type_id));
      }
      if (!$entity_type_id || !in_array($entity_type_id, $exportable_entity_type_ids, TRUE)) {
        if (!($entity_type_id = $this->io()->choice($this->cliActions->selectExportEntityTypeQuestion(), $this->cliActions->getEntityTypeLabels($exportable_entity_type_ids)))) {
          throw new UserAbortException();
        }
      }
      $entity_type_ids = [$entity_type_id];
      $confirmation_message = $this->cliActions->exportConfirmationQuestion(FALSE, $entity_type_id);
    }

    if (!$this->io()->confirm($confirmation_message)) {
      throw new UserAbortException();
    }

    $this->cliActions->exportViews($this->io(), $entity_type_ids, !empty($options['overwrite']));
  }

  /**
   * Asks the user to select an entity type.
   */
  #[CLI\Hook(type: HookManager::INTERACT, target: 'trash:restore')]
  public function hookInteractRestore(Input $input): void {
    $this->interactEntityType($input, 'restore');
  }

  /**
   * Asks the user to select an entity type.
   */
  #[CLI\Hook(type: HookManager::INTERACT, target: 'trash:purge')]
  public function hookInteractPurge(Input $input): void {
    $this->interactEntityType($input, 'purge');
  }

  /**
   * Prompts for the entity type when it is missing and '--all' is not set.
   */
  protected function interactEntityType(Input $input, string $operation): void {
    if (!$input->getArgument('entity_type_id') && !$input->getOption('all')) {
      $entity_type_ids = $this->cliActions->getEnabledEntityTypes();

      if ($entity_type_ids !== []) {
        if (!$choice = $this->io()->choice($this->cliActions->selectEntityTypeQuestion($operation), array_combine($entity_type_ids, $entity_type_ids))) {
          throw new UserAbortException();
        }

        $input->setArgument('entity_type_id', $choice);
      }
      else {
        throw new CommandFailedException($this->cliActions->noEntityTypesEnabledMessage());
      }
    }
  }

  /**
   * Validates that the given entity type ID is a Trash-enabled entity type.
   *
   * The interact hooks only prompt for (and thereby validate) the entity type
   * ID when the argument is missing; a value supplied directly on the command
   * line skips that path and would otherwise fail deeper with a raw exception.
   *
   * @throws \Drush\Exceptions\CommandFailedException
   *   Thrown when the entity type ID is not a Trash-enabled entity type.
   */
  protected function validateEntityTypeId(?string $entity_type_id): void {
    if (!$this->cliActions->isTrashEnabledType($entity_type_id)) {
      throw new CommandFailedException($this->cliActions->invalidEntityTypeMessage($entity_type_id));
    }
  }

  /**
   * Prompts the user to confirm the command arguments.
   */
  protected function getConfirmation(string $operation, ?string $entity_type_id = NULL, ?array $entity_ids = NULL, array $options = ['all' => FALSE]): void {
    if (!$this->io()->confirm($this->cliActions->confirmationQuestion($operation, $entity_type_id, $entity_ids, (bool) $options['all']))) {
      throw new UserAbortException();
    }
  }

}
