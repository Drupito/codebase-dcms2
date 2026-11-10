<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\trash_test\Entity\TrashTestEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the {language} slug lifecycle for the restore and purge routes.
 *
 * The slug in the link templates and the matching route parameter have to
 * appear when a site becomes multilingual and disappear when it drops back to
 * one language, and operation links have to pin the translation they act on.
 *
 * @see \Drupal\trash\Hook\TrashHooks::configurableLanguageInsert()
 * @see \Drupal\trash\Hook\TrashHooks::configurableLanguageDelete()
 * @see \Drupal\trash\Routing\RouteSubscriber
 * @see \Drupal\trash\Hook\TrashEntityHooks::entityOperationAlter()
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashLanguageLifecycleTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * Adding and deleting a language updates link templates and routes together.
   */
  public function testLanguageLifecycleUpdatesLinkTemplatesAndRoutes(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route_builder = $this->container->get('router.builder');

    // Monolingual: no {language} slug in the link templates or the routes.
    $entity_type = $this->getEntityTypeManager()->getDefinition('trash_test_entity');
    $this->assertStringNotContainsString('{language}', $entity_type->getLinkTemplate('restore'));
    $this->assertStringNotContainsString('{language}', $entity_type->getLinkTemplate('purge'));
    $route = $route_provider->getRouteByName('entity.trash_test_entity.restore');
    $this->assertStringNotContainsString('{language}', $route->getPath());
    $this->assertArrayNotHasKey('language', $route->getOption('parameters'));

    // Adding a second language must expose the slug in the entity type
    // definitions and mark the router for rebuild.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $entity_type = $this->getEntityTypeManager()->getDefinition('trash_test_entity');
    $this->assertStringContainsString('{language}', $entity_type->getLinkTemplate('restore'));
    $this->assertStringContainsString('{language}', $entity_type->getLinkTemplate('purge'));
    $this->assertTrue($route_builder->rebuildIfNeeded());
    $route = $route_provider->getRouteByName('entity.trash_test_entity.restore');
    $this->assertStringContainsString('{language}', $route->getPath());
    $this->assertArrayHasKey('language', $route->getOption('parameters'));
    $this->assertArrayHasKey('language', $route->getDefaults());
    $this->assertNull($route->getDefault('language'));

    // Deleting the second language must drop the slug from both layers again.
    ConfigurableLanguage::load('fr')->delete();
    $entity_type = $this->getEntityTypeManager()->getDefinition('trash_test_entity');
    $this->assertStringNotContainsString('{language}', $entity_type->getLinkTemplate('restore'));
    $this->assertStringNotContainsString('{language}', $entity_type->getLinkTemplate('purge'));
    $this->assertTrue($route_builder->rebuildIfNeeded());
    $route = $route_provider->getRouteByName('entity.trash_test_entity.restore');
    $this->assertStringNotContainsString('{language}', $route->getPath());
    $this->assertArrayNotHasKey('language', $route->getOption('parameters'));

    // Restore and purge URLs stay generable after dropping back to one
    // language.
    $entity = TrashTestEntity::create(['label' => 'Test']);
    $entity->save();
    $entity->delete();
    $entity = $this->loadTrashedEntity('trash_test_entity', (string) $entity->id());
    $this->assertNotEmpty($entity->toUrl('restore')->toString());
    $this->assertNotEmpty($entity->toUrl('purge')->toString());
  }

  /**
   * Restore/purge operation links pin the translation they act on.
   */
  public function testOperationLinksPinTranslationLanguage(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->setUpCurrentUser([], [
      'access trash',
      'view deleted entities',
      'restore trash_test_entity entities',
      'purge trash_test_entity entities',
    ]);

    $entity = TrashTestEntity::create(['label' => 'EN']);
    $entity->addTranslation('fr', ['label' => 'FR']);
    $entity->save();
    $id = (string) $entity->id();
    $entity->delete();

    $entity = $this->loadTrashedEntity('trash_test_entity', $id);
    $module_handler = $this->container->get('module_handler');

    // Default translation rows point at the default language explicitly, so
    // the operation scope cannot depend on the negotiated content language.
    $operations = [];
    $module_handler->alter('entity_operation', $operations, $entity);
    $this->assertSame('en', $operations['restore']['url']->getRouteParameters()['language'] ?? NULL);
    $this->assertSame('en', $operations['purge']['url']->getRouteParameters()['language'] ?? NULL);

    // Non-default translation rows point at their own language.
    $translation = $entity->getTranslation('fr');
    $operations = [];
    $module_handler->alter('entity_operation', $operations, $translation);
    $this->assertSame('fr', $operations['restore']['url']->getRouteParameters()['language'] ?? NULL);
    $this->assertSame('fr', $operations['purge']['url']->getRouteParameters()['language'] ?? NULL);
  }

}
