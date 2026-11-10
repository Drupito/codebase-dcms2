<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Functional;

use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\trash\Trash;
use Drupal\trash_test\Entity\TrashTestEntity;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the trash ignore functionality.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashIgnoreTest extends BrowserTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'trash',
    'trash_test',
    'user',
    'language',
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
    // Create Basic page node type.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType([
        'type' => 'page',
        'name' => 'Basic Page',
        'display_submitted' => FALSE,
      ]);
    }
  }

  /**
   * Test ignore context on multilingual sites.
   */
  public function testIgnoreOnMultilingualSite(): void {
    // Create test language.
    ConfigurableLanguage::createFromLangcode('de')->save();

    $trash_user = $this->drupalCreateUser([
      'access content',
      'edit own page content',
      'delete own page content',
      'access trash',
      'view deleted entities',
      'access administration pages',
      'view the administration theme',
      'administer trash_test',
    ]);

    // Create a trashed node.
    $node = $this->drupalCreateNode();
    $node
      ->setOwner($trash_user)
      ->save();

    // Set the interface language to use the preferred administration language
    // and then the URL.
    /** @var \Drupal\language\LanguageNegotiatorInterface $language_negotiator */
    $language_negotiator = $this->container->get('language_negotiator');
    $language_negotiator->saveConfiguration('language_interface', [
      'language-user-admin' => 1,
      'language-url' => 2,
      'language-selected' => 3,
    ]);

    // Use a custom user admin language.
    $trash_user
      ->set('preferred_admin_langcode', 'de')
      ->save();

    // Log the user in.
    $this->drupalLogin($trash_user);

    // Delete the node.
    $this->drupalGet($node->toUrl('delete-form'));
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusCodeEquals(200);

    // Trigger the language negotiation earlier than normal.
    \Drupal::keyValue('trash_test')->set('early_language_negotiation', TRUE);

    $this->drupalGet($node->toUrl());
    // The user cannot view the trashed content without the 'in_trash' query
    // string / context.
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet($node->toUrl(NULL, [
      'query' => [
        'in_trash' => '1',
      ],
    ]));
    // The user can now view the trashed content.
    $this->assertSession()->statusCodeEquals(200);

    // The 'in_trash' context must not open up editing or deleting the trashed
    // content, even though the user could do both while it was live. A plain
    // delete would also bypass the purge permission, because the storage
    // hard-deletes outside of the 'active' trash context.
    $this->drupalGet($node->toUrl('edit-form', [
      'query' => [
        'in_trash' => '1',
      ],
    ]));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet($node->toUrl('delete-form', [
      'query' => [
        'in_trash' => '1',
      ],
    ]));
    $this->assertSession()->statusCodeEquals(403);

    // Deleting a live entity with the 'in_trash' parameter appended must not
    // hard-delete it: the request-wide 'ignore' context granted by the
    // parameter makes the storage skip the soft-delete, without the purge
    // permission. Delete forms are blocked outright by the formAlter() guard.
    $live_node = $this->drupalCreateNode();
    $live_node->setOwner($trash_user)->save();
    $this->drupalGet($live_node->toUrl('delete-form', ['query' => ['in_trash' => '1']]));
    $this->assertSession()->statusCodeEquals(406);

    // The guard only applies to trash-enabled entity types: a delete form for
    // any other entity type must keep working in a non-active trash context.
    $trash_test_entity = TrashTestEntity::create(['label' => 'Not trash enabled']);
    $trash_test_entity->save();
    $this->drupalGet($trash_test_entity->toUrl('delete-form', ['query' => ['in_trash' => '1']]));
    $this->assertSession()->statusCodeEquals(200);

    // Non-form delete paths have no such guard, so the 'ignore' context has
    // to be dropped for state-changing requests outside the trash routes,
    // making this POST soft-delete the entity.
    $url = Url::fromRoute('trash_test.node_delete', ['node' => $live_node->id()], [
      'query' => ['in_trash' => '1'],
      'absolute' => TRUE,
    ]);
    $response = $this->getHttpClient()->request('POST', $url->toString(), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    $this->assertEquals(200, $response->getStatusCode());

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $reloaded = $this->container->get('trash.manager')
      ->executeInTrashContext('ignore', fn () => $storage->loadUnchanged($live_node->id()));
    $this->assertNotNull($reloaded, 'The node still exists after being deleted with in_trash=1.');
    $this->assertTrue(Trash::entityIsDeleted($reloaded));
  }

}
