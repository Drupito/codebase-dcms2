<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\trash\Handler\TrashHandlerPass;
use Drupal\trash\Hook\TrashHandler\RedirectTrashHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that trash handlers are only registered for enabled entity types.
 *
 * TrashManager gets its handlers through addHandler() setter calls, so
 * building 'trash.manager' builds every registered handler along with
 * whatever those handlers ask for in their constructors. TrashIgnoreSubscriber
 * takes the trash manager and listens on kernel.request, so that happens on
 * every request.
 *
 * TrashHandlerPass also adds a setTrashManager() call to
 * 'path_alias.repository', and the 'router.route_provider' constructor reaches
 * that service through 'path_processor_manager' and
 * 'path_alias.path_processor'. Handlers are therefore built while the route
 * provider is still loading. A handler that reaches the router from there
 * gets a ServiceCircularReferenceException.
 *
 * Two rules follow. Only enabled entity types get a handler registered, and a
 * handler must not take a service that resolves configuration in its
 * constructor. 'redirect.repository' is one of those services. It reads
 * 'redirect.settings' in its own constructor, and with a config override that
 * negotiates the interface language (Domain Config's DomainConfigOverrider
 * does this) plus the 'language-user-admin' method enabled, reading config
 * builds the router. A handler holding it as a constructor argument produces
 * this cycle:
 *
 * @code
 * // Constructor references.
 * router.route_provider -> path_processor_manager
 *   -> path_alias.path_processor -> path_alias.manager
 *   -> path_alias.repository -> trash.manager -> trash handler
 *   -> redirect.repository
 * // Then at runtime, from the redirect repository's constructor.
 * config.factory -> language_manager -> LanguageNegotiationUserAdmin
 *   -> router -> router.no_access_checks -> router.route_provider
 * @endcode
 *
 * Core issue #3571046 made PathProcessorManager collect its processors through
 * a tagged iterator. From core 11.4 the 'path_processor_manager' to
 * 'path_alias.path_processor' edge is no longer eager, so the cycle cannot
 * form. Every other edge above holds on all supported core versions.
 *
 * @see \Drupal\trash\Hook\TrashHandler\RedirectTrashHandler
 * @see \Drupal\Tests\trash\Functional\TrashRedirectCircularReferenceTest
 * @see https://www.drupal.org/node/3571046
 */
#[CoversClass(TrashHandlerPass::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashHandlerRegistrationTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'link',
    'path_alias',
    'redirect',
    'views',
  ];

  /**
   * {@inheritdoc}
   *
   * The 'redirect' entity type is deliberately not enabled, so that a tagged
   * handler exists for an entity type trash is not enabled for.
   */
  protected array $additionalTrashEntityTypes = ['path_alias' => []];

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('redirect');
    $this->installConfig(['redirect']);
  }

  /**
   * Tests that no handler is registered for a disabled entity type.
   */
  public function testHandlersAreNotRegisteredForDisabledEntityTypes(): void {
    $trash_manager = $this->getTrashManager();

    // Every entity type trash.services.yml tags a handler for, plus
    // trash_test_entity, which gets an auto-registered DefaultTrashHandler.
    // The tags are unconditional, so handlers exist even for entity types
    // whose module is not installed here.
    $candidates = [
      'file',
      'menu_link_content',
      'node',
      'path_alias',
      'redirect',
      'taxonomy_term',
      'trash_test_entity',
    ];

    $enabled = array_values(array_filter($candidates, fn (string $entity_type_id): bool =>
      $trash_manager->isEntityTypeEnabled($entity_type_id)
    ));
    $this->assertSame(['node', 'path_alias', 'trash_test_entity'], $enabled);

    // Only enabled entity types should have a handler registered with the
    // trash manager. Registering the others makes 'trash.manager' and therefore
    // every bootstrap service that depends on it instantiate handlers, and
    // their dependencies, that can never be used.
    $registered = array_values(array_filter($candidates, fn (string $entity_type_id): bool =>
      $trash_manager->getHandler($entity_type_id) !== NULL
    ));
    $this->assertSame($enabled, $registered);
  }

  /**
   * Tests that bootstrap services do not instantiate 'redirect.repository'.
   */
  public function testTrashManagerDoesNotInstantiateRedirectRepository(): void {
    $container = $this->container;

    // 'path_alias.repository' is built while resolving inbound path
    // processors, i.e. very early in the request.
    // TrashHandlerPass adds a setTrashManager() call to it, so getting it also
    // builds 'trash.manager'.
    $container->get('path_alias.repository');
    $this->assertTrue($container->initialized('trash.manager'));

    // Trash is not enabled for the 'redirect' entity type, so nothing in this
    // graph has any reason to build the Redirect module's repository.
    $this->assertFalse(
      $container->initialized('redirect.repository'),
      "Building 'path_alias.repository' must not instantiate 'redirect.repository'.",
    );
  }

  /**
   * Tests that handlers are registered once an entity type is enabled.
   */
  public function testHandlersAreRegisteredForEnabledEntityTypes(): void {
    $this->enableEntityTypesForTrash(['redirect' => []]);

    $trash_manager = $this->getTrashManager();
    $this->assertTrue($trash_manager->isEntityTypeEnabled('redirect'));
    $this->assertInstanceOf(RedirectTrashHandler::class, $trash_manager->getHandler('redirect'));

    // A registered handler is built along with the trash manager, so it must
    // not pull 'redirect.repository' in with it.
    $this->container->get('path_alias.repository');
    $this->assertFalse(
      $this->container->initialized('redirect.repository'),
      "Registering RedirectTrashHandler must not instantiate 'redirect.repository'.",
    );
  }

}
