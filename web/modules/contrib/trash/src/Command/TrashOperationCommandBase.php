<?php

declare(strict_types=1);

namespace Drupal\trash\Command;

use Drupal\trash\TrashCliActions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base command for the restore and purge operations on the core `dr` CLI.
 */
abstract class TrashOperationCommandBase extends Command {

  public function __construct(
    protected readonly TrashCliActions $trashCliActions,
  ) {
    parent::__construct();
  }

  /**
   * Returns the operation this command performs, either 'restore' or 'purge'.
   */
  abstract protected function getOperation(): string;

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $operation = $this->getOperation();
    $this
      ->addArgument('entity_type_id', InputArgument::OPTIONAL, sprintf('The entity type to %s.', $operation))
      ->addArgument('entity_ids', InputArgument::OPTIONAL, sprintf('A comma-separated list of entity IDs to %s.', $operation))
      ->addOption('all', NULL, InputOption::VALUE_NONE, sprintf('%s data for all entity types.', ucfirst($operation)));
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output): void {
    if ($input->getArgument('entity_type_id') || $input->getOption('all')) {
      return;
    }
    $entity_type_ids = $this->trashCliActions->getEnabledEntityTypes();
    if ($entity_type_ids === []) {
      throw new RuntimeException($this->trashCliActions->noEntityTypesEnabledMessage());
    }
    $io = new SymfonyStyle($input, $output);
    $choice = $io->choice(
      $this->trashCliActions->selectEntityTypeQuestion($this->getOperation()),
      array_combine($entity_type_ids, $entity_type_ids),
    );
    $input->setArgument('entity_type_id', $choice);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $operation = $this->getOperation();
    $all = (bool) $input->getOption('all');
    $entity_type_id = $input->getArgument('entity_type_id');
    $entity_ids = $this->parseIds($input->getArgument('entity_ids'));

    if (!$all && !$this->trashCliActions->isTrashEnabledType($entity_type_id)) {
      $io->error($this->trashCliActions->invalidEntityTypeMessage($entity_type_id));
      return Command::INVALID;
    }

    if (!$io->confirm($this->trashCliActions->confirmationQuestion($operation, $entity_type_id, $entity_ids, $all))) {
      return Command::SUCCESS;
    }

    if ($all) {
      $entity_type_ids = $this->trashCliActions->getEnabledEntityTypes();
      $entity_ids = NULL;
    }
    else {
      $entity_type_ids = [$entity_type_id];
    }

    $count = $this->trashCliActions->performOperation($io, $operation, $entity_type_ids, $entity_ids);
    $io->success($this->trashCliActions->resultMessage($operation, $count));
    return Command::SUCCESS;
  }

  /**
   * Parses a comma-separated list of entity IDs into an array.
   */
  private function parseIds(?string $entity_ids): ?array {
    if ($entity_ids === NULL || trim($entity_ids) === '') {
      return NULL;
    }
    $ids = array_values(array_filter(array_map('trim', explode(',', $entity_ids)), 'strlen'));
    return $ids === [] ? NULL : $ids;
  }

}
