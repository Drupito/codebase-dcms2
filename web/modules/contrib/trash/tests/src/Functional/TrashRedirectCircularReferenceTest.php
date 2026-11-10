<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationSelected;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\user\Plugin\LanguageNegotiation\LanguageNegotiationUserAdmin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that trash handlers do not introduce a service reference cycle.
 *
 * TrashHandlerPass adds a setTrashManager() call to 'path_alias.repository',
 * which the 'router.route_provider' constructor reaches through the inbound
 * path processors. Trash handlers are therefore built while the route provider
 * is still loading, and a handler that reaches the router from there gets a
 * ServiceCircularReferenceException.
 *
 * A kernel test cannot cover this. It never nests a 'path_alias.repository'
 * build inside a 'router.route_provider' build, and that ordering is the whole
 * point.
 *
 * Core issue #3571046 made PathProcessorManager collect its processors through
 * a tagged iterator, so from core 11.4 the route provider no longer reaches
 * the path alias repository. The cycle is reachable only on core 11.3, which
 * is what .gitlab-ci.yml pins the default pipeline to. On newer core this
 * still covers that the site boots and serves requests with Trash, Redirect
 * and a language-keyed config override installed together.
 *
 * @see \Drupal\Tests\trash\Kernel\TrashHandlerRegistrationTest
 * @see \Drupal\trash_language_override_test\LanguageAwareConfigOverride
 * @see https://www.drupal.org/node/3571046
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashRedirectCircularReferenceTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'link',
    'node',
    'path_alias',
    'redirect',
    'trash',
    'trash_language_override_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Enable the 'Account administrator pages' interface language negotiation
    // method. Unlike the methods enabled by default,
    // LanguageNegotiationUserAdmin depends on the router, so negotiating the
    // interface language while the router is being built is fatal.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', [
        LanguageNegotiationUserAdmin::METHOD_ID => -20,
        LanguageNegotiationUrl::METHOD_ID => -19,
        LanguageNegotiationSelected::METHOD_ID => -15,
      ])
      ->save();

    // Enable trash for path aliases but not for redirects. This is what makes
    // 'path_alias.repository' depend on 'trash.manager'.
    $this->config('trash.settings')
      ->set('enabled_entity_types', ['node' => [], 'path_alias' => []])
      ->save();

    $this->rebuildAll();
  }

  /**
   * Tests that requests which build the router before anything else succeed.
   *
   * 'options_request_listener' takes '@router.route_provider' and is one of the
   * first 'kernel.request' listeners to be instantiated, so an OPTIONS request
   * is the most reliable way to reach the router before anything has warmed it.
   */
  public function testOptionsRequestDoesNotTriggerCircularReference(): void {
    $response = $this->getHttpClient()->request('OPTIONS', $this->buildUrl('/admin/content'), [
      'http_errors' => FALSE,
      'headers' => [
        'User-Agent' => drupal_generate_test_ua($this->databasePrefix),
      ],
    ]);

    $body = (string) $response->getBody();
    $this->assertStringNotContainsString('Circular reference detected', $body);
    $this->assertSame(200, $response->getStatusCode(), $body);
  }

  /**
   * Tests that a regular page request still works.
   */
  public function testFrontPageIsNotBroken(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Circular reference detected');
  }

}
