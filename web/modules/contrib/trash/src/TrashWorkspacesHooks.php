<?php

declare(strict_types=1);

namespace Drupal\trash;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\workspaces\Hook\WorkspacesHooks;

/**
 * Decorates WorkspacesHooks to wrap cron() in an inactive trash context.
 *
 * Extends WorkspacesHooks so that "instanceof WorkspacesHooks" holds for
 * other modules that type-hint the concrete class in their own constructor
 * (e.g. wse's WseOverrideHooks) — decorating a class-named service replaces
 * what resolves for that class everywhere, so a decorator that ISN'T a
 * WorkspacesHooks itself makes any such consumer fatal with a TypeError.
 *
 * Deliberately NOT in src/Hook: Drupal's hook-collector scans every class
 * under that namespace to check for #[Hook] attributes, which requires
 * loading each one, which requires resolving this class's parent. On a site
 * or test where the workspaces module isn't installed, WorkspacesHooks isn't
 * autoloadable at all, so merely having this class under src/Hook would fatal
 * with "class not found" on every request, regardless of whether workspaces
 * is actually in use.
 *
 * This does NOT call parent::__construct(): WorkspacesHooks's own
 * dependencies (WorkspaceManagerInterface and friends) aren't reliably
 * autowired in every context this service gets compiled in (e.g. kernel
 * tests that don't install the workspaces module), so re-resolving them here
 * would reintroduce a hard container-compilation failure. Instead, every
 * method WorkspacesHooks implements is explicitly overridden to delegate to
 * $inner (injected via the decoration reference, exactly as the container
 * already resolves the undecorated service): this class's own inherited
 * properties are never touched, so leaving them uninitialized is safe.
 *
 * @see \Drupal\trash\TrashServiceProvider::alter()
 */
class TrashWorkspacesHooks extends WorkspacesHooks {

  public function __construct(
    protected WorkspacesHooks $inner,
    protected TrashManagerInterface $trashManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    return $this->inner->help($route_name, $route_match);
  }

  /**
   * {@inheritdoc}
   */
  public function modulePreinstall(string $module): void {
    $this->inner->modulePreinstall($module);
  }

  /**
   * {@inheritdoc}
   */
  public function menuLinkContentUpdate(EntityInterface $entity): void {
    $this->inner->menuLinkContentUpdate($entity);
  }

  /**
   * Wraps WorkspacesHooks::cron() in an inactive trash context.
   *
   * Ensures that revisions are actually deleted when workspace data is purged.
   */
  public function cron(): void {
    $this->trashManager->executeInTrashContext('inactive', function () {
      $this->inner->cron();
    });
  }

}
