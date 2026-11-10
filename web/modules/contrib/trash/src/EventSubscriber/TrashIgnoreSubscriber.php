<?php

declare(strict_types=1);

namespace Drupal\trash\EventSubscriber;

use Drupal\Core\DefaultContent\PreImportEvent;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountEvents;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSetEvent;
use Drupal\Core\Update\UpdateKernel;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\trash\TrashManagerInterface;
use Drupal\workspaces\Event\WorkspacePostPublishEvent;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Drupal\workspaces\Event\WorkspacePublishEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listens to events where trash context has to be ignored.
 */
class TrashIgnoreSubscriber implements EventSubscriberInterface {

  /**
   * The trash context to restore once the paired "restore" event fires.
   *
   * NULL when no ignoreTrashContext()/restoreTrashContext() bracket is open.
   */
  protected ?string $previousTrashContext = NULL;

  public function __construct(
    protected HttpKernelInterface $httpKernel,
    #[AutowireServiceClosure('entity_type.manager')]
    protected \Closure $entityTypeManager,
    protected TrashManagerInterface $trashManager,
    protected RouteMatchInterface $routeMatch,
    protected AccountInterface $currentUser,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Sets the trash context to ignore if needed.
   *
   * @param \Symfony\Component\HttpKernel\Event\KernelEvent $event
   *   The KernelEvent to process.
   */
  public function onRequestPreRouting(KernelEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    // Specify the default trash context.
    $this->setDefaultTrashContext($event->getKernel(), $this->currentUser, $event->getRequest());
  }

  /**
   * Sets the default trash context for the currently set user.
   */
  public function onSetAccount(AccountSetEvent $event): void {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      try {
        $this->setDefaultTrashContext($this->httpKernel, $event->getAccount(), $request);
      }
      catch (ParamNotConvertedException) {
        // This exception may be thrown while Drupal core tries to cache
        // the permission variable cache context as the language can be within
        // the cache context.
        // It's fine to ignore as it's mainly used to trigger 404s, and will be
        // rechecked by the ::onRequestPreRouting() listener anyway.
      }
    }
  }

  /**
   * Specifies the default trash context for the given user and request.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The current HTTP kernel.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The set account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  protected function setDefaultTrashContext(HttpKernelInterface $http_kernel, AccountInterface $account, Request $request): void {
    // This is needed so upgrades affecting entities will affect all entities,
    // no matter if they have been trashed.
    if ($http_kernel instanceof UpdateKernel) {
      $this->trashManager->setTrashContext('ignore');
    }
    elseif ($request->query->has('in_trash')) {
      // Only respect the "in_trash" query string if the user has the permission
      // to use it. This stops the leakage of whether an entity is trashed or
      // not to anonymous users through the response's status code.
      if (
        (
          $account->hasPermission('administer trash') ||
          $account->hasPermission('access trash') ||
          $account->hasPermission('view deleted entities')
        )
        && !$this->isUnsafeNonTrashRequest($request)
      ) {
        $this->trashManager->setTrashContext('ignore');
      }
      else {
        // Ensure that it is now active. This is useful in the event that the
        // user account is switched multiple times within the same request.
        $this->trashManager->setTrashContext('active');
      }
    }
  }

  /**
   * Checks if honoring "in_trash" here would let a request skip soft-delete.
   *
   * ::onRequest() already downgrades the context to 'active' for a
   * state-changing request outside the trash UI, but setDefaultTrashContext()
   * also runs on every account switch (via ::onSetAccount()), which would
   * otherwise re-escalate the context back to 'ignore' and re-open the
   * hard-delete path. Applying the same guard here keeps the downgrade
   * durable across account switches.
   *
   * Returns TRUE only once routing has run, so the pre-routing pass (where the
   * route is not known yet) still grants 'ignore' and lets the param converter
   * upcast the trashed entity. Trash routes keep 'ignore' either way.
   */
  protected function isUnsafeNonTrashRequest(Request $request): bool {
    $route = $this->routeMatch->getRouteObject();
    return $route !== NULL
      && !$request->isMethodSafe()
      && !$route->getOption('_trash_route');
  }

  /**
   * Sets the trash context to ignore if needed.
   *
   * @param \Symfony\Component\HttpKernel\Event\KernelEvent $event
   *   The KernelEvent to process.
   */
  public function onRequest(KernelEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // The 'in_trash' query parameter grants the whole request the 'ignore'
    // context before routing, so route parameters can be upcast to trashed
    // entities. Once the route is known, drop that context back to 'active'
    // for state-changing requests outside the trash UI: with 'ignore' still
    // set, adding the parameter to a plain entity delete form would
    // hard-delete the entity, skipping both the soft-delete and the purge
    // permission. Trash routes keep the context, their forms rely on it, and
    // so does the update kernel. The route-based grants below run after this
    // and re-apply theirs.
    $request = $event->getRequest();
    if (
      !$request->isMethodSafe()
      && $request->query->has('in_trash')
      && !$event->getKernel() instanceof UpdateKernel
      && $this->trashManager->getTrashContext() === 'ignore'
      && !$this->routeMatch->getRouteObject()?->getOption('_trash_route')
    ) {
      $this->trashManager->setTrashContext('active');
    }

    // Some entity types that act as bundles for other entities have a custom
    // UI-only delete protection (i.e. a content type can not be deleted if
    // there are existing nodes of that type.) Trash needs to allow this
    // protection to work even when there are trashed entities of that type.
    if ($entity_form = $this->routeMatch->getRouteObject()->getDefault('_entity_form')) {
      // If no operation is provided, use 'default'.
      $entity_form .= '.default';
      [$entity_type_id, $operation] = explode('.', $entity_form);
      /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
      $entity_type_manager = ($this->entityTypeManager)();
      $entity_type = $entity_type_manager->getDefinition($entity_type_id);

      if ($operation === 'delete' && ($bundle_of = $entity_type->getBundleOf())) {
        if (!$this->trashManager->isEntityTypeEnabled($entity_type_id)
          && $this->trashManager->isEntityTypeEnabled($bundle_of)
        ) {
          $this->trashManager->setTrashContext('ignore');
        }
      }
    }

    // Allow trashed entities to be displayed on the workspace manage page.
    if ($this->routeMatch->getRouteName() === 'entity.workspace.canonical') {
      $this->trashManager->setTrashContext('ignore');
    }

    // Allow trashed entities to be loaded on trash listing routes, including
    // during bulk operations form submissions.
    if (str_starts_with($this->routeMatch->getRouteName() ?? '', 'trash.admin_content_trash')) {
      $this->trashManager->setTrashContext('ignore');
    }
  }

  /**
   * Sets the trash context to 'ignore'.
   */
  public function ignoreTrashContext(): void {
    $this->previousTrashContext = $this->trashManager->getTrashContext();
    $this->trashManager->setTrashContext('ignore');
  }

  /**
   * Restores the trash context to what it was before ignoreTrashContext().
   */
  public function restoreTrashContext(): void {
    $this->trashManager->setTrashContext($this->previousTrashContext ?? 'active');
    $this->previousTrashContext = NULL;
  }

  /**
   * Resets a trash context left stuck at 'ignore' by an uncaught exception.
   *
   * The ignoreTrashContext()/restoreTrashContext() pair is wired to paired
   * events (workspace pre/post-publish, migrate pre/post-row-delete) on the
   * assumption that the closing event always fires. If the bracketed
   * operation throws, the closing event never runs and the context stays at
   * 'ignore' for the rest of the request, so every later entity query or
   * access check stops filtering trashed entities. Restore it here so the
   * damage is contained to the request that failed.
   */
  public function onException(): void {
    if ($this->previousTrashContext !== NULL) {
      $this->trashManager->setTrashContext($this->previousTrashContext);
      $this->previousTrashContext = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    // Our ignore subscriber needs to run before language negotiation (which has
    // a priority of 255), but immediately after the authentication subscriber
    // (which has a priority of 300) in order to allow route enhancers (e.g.
    // entity param converter) to load the deleted entity.
    // It's possible for the language negotiation to be triggered earlier than
    // expected by a different subscriber with a priority of 299, so this
    // listener must be called immediately after the authentication subscriber
    // to ensure the user has the appropriate trash context.
    // This critical requirement is further enforced by the trash service
    // provider.
    $events[KernelEvents::REQUEST][] = ['onRequestPreRouting', 299];
    // Add a subscriber that reacts to when the user account is set.
    // This allows the default trash context to be set or reset based on the
    // currently set user, even before the onRequestPreRouting listener is
    // called.
    $events[AccountEvents::SET_USER][] = ['onSetAccount'];

    // Add another subscriber for setting the ignore trash context when the
    // current route is known.
    $events[KernelEvents::REQUEST][] = ['onRequest'];

    // Safety net: reset a context left stuck at 'ignore' by an exception that
    // escaped one of the ignoreTrashContext()/restoreTrashContext() brackets
    // below.
    $events[KernelEvents::EXCEPTION][] = ['onException'];

    if (class_exists(WorkspacePublishEvent::class)) {
      $events[WorkspacePrePublishEvent::class][] = ['ignoreTrashContext'];
      $events[WorkspacePostPublishEvent::class][] = ['restoreTrashContext'];
    }
    if (class_exists(PreImportEvent::class)) {
      $events[PreImportEvent::class][] = ['ignoreTrashContext'];
    }
    if (class_exists(MigrateEvents::class)) {
      $events[MigrateEvents::PRE_ROW_DELETE][] = ['ignoreTrashContext'];
      $events[MigrateEvents::POST_ROW_DELETE][] = ['restoreTrashContext'];
    }

    return $events;
  }

}
