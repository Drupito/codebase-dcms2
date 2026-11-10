<?php

declare(strict_types=1);

namespace Drupal\trash\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a redirect's source is not held by a trashed redirect.
 */
#[Constraint(
  id: 'TrashedRedirectSource',
  label: new TranslatableMarkup('Redirect source not held by a trashed redirect', [], ['context' => 'Validation'])
)]
class TrashedRedirectSourceConstraint extends SymfonyConstraint {

  /**
   * The error message when the user cannot restore the trashed redirect.
   */
  public string $message = 'The source path %source is used by a redirect in the trash.';

  /**
   * The error message when the user can restore the trashed redirect.
   */
  public string $restoreMessage = 'The source path %source is used by a redirect in the trash. <a href=":url">Restore it</a> to edit it.';

}
