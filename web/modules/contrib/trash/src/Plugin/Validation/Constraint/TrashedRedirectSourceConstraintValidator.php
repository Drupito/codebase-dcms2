<?php

declare(strict_types=1);

namespace Drupal\trash\Plugin\Validation\Constraint;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\trash\TrashManagerInterface;
use Drupal\trash\Trash;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the TrashedRedirectSource constraint.
 *
 * A redirect trashed in a workspace keeps its hash in the base table until the
 * workspace is published, and the hash has a unique key there. The Redirect
 * module's own check does not see trashed redirects, so a new redirect from
 * the same source would pass validation and then fail to save.
 */
class TrashedRedirectSourceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected Connection $connection,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrashManagerInterface $trashManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $redirect = $value;
    assert($redirect instanceof Redirect);
    assert($constraint instanceof TrashedRedirectSourceConstraint);

    // The base table holds the hash that the unique key covers, also inside a
    // workspace, so it is read directly instead of through an entity query.
    $source = $redirect->getSource();
    $query = $this->connection->select('redirect', 'r')
      ->fields('r', ['rid'])
      ->condition('hash', Redirect::generateHash($source['path'] ?? '', $source['query'] ?? [], $redirect->get('language')->value));
    if (!$redirect->isNew()) {
      $query->condition('rid', $redirect->id(), '<>');
    }
    $existing_id = $query->execute()->fetchField();
    if ($existing_id === FALSE) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('redirect');
    $existing_redirect = $this->trashManager->executeInTrashContext('ignore', fn () => $storage->load($existing_id));
    if (!$existing_redirect || !Trash::entityIsDeleted($existing_redirect)) {
      return;
    }

    $parameters = ['%source' => ltrim($redirect->getSourcePathWithQuery(), '/')];
    if (!$existing_redirect->access('restore')) {
      $this->context->addViolation($constraint->message, $parameters);
      return;
    }
    $parameters[':url'] = $existing_redirect->toUrl('restore')->toString();
    $this->context->addViolation($constraint->restoreMessage, $parameters);
  }

}
