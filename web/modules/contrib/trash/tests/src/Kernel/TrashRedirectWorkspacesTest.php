<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Trash integration with Redirect and Workspaces.
 */
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashRedirectWorkspacesTest extends TrashKernelTestBase {

  use WorkspaceTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'link',
    'path',
    'path_alias',
    'redirect',
    'views',
    'workspaces',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = ['redirect' => []];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->workspaceManager = \Drupal::service('workspaces.manager');

    $this->installSchema('workspaces', ['workspace_association']);
    if (isset(workspaces_schema()['workspace_association_revision'])) {
      $this->installSchema('workspaces', ['workspace_association_revision']);
    }
    $this->installEntitySchema('workspace');

    $this->workspaces['stage'] = Workspace::create(['id' => 'stage', 'label' => 'Stage']);
    $this->workspaces['stage']->save();

    $this->setCurrentUser($this->createUser([
      'view any workspace',
    ]));
  }

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('redirect');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['redirect', 'system']);
  }

  /**
   * Tests flipping a node's alias between two values, without a workspace.
   */
  public function testAliasFlipRestoresTrashedRedirect(): void {
    $this->assertAliasFlipRestoresTrashedRedirect();
  }

  /**
   * Tests flipping a node's alias between two values inside a workspace.
   */
  public function testAliasFlipRestoresTrashedRedirectInWorkspace(): void {
    $entity_type = $this->entityTypeManager->getDefinition('redirect');
    if (!$this->container->get('workspaces.information')->isEntityTypeSupported($entity_type)) {
      $this->markTestSkipped('The installed version of the Redirect module does not make the redirect entity type workspace-supported.');
    }

    $this->switchToWorkspace('stage');
    $this->assertAliasFlipRestoresTrashedRedirect();
  }

  /**
   * Tests a new redirect from the source of a redirect trashed in a workspace.
   */
  public function testNewRedirectFromSourceTrashedInWorkspace(): void {
    $entity_type = $this->entityTypeManager->getDefinition('redirect');
    if (!$this->container->get('workspaces.information')->isEntityTypeSupported($entity_type)) {
      $this->markTestSkipped('The installed version of the Redirect module does not make the redirect entity type workspace-supported.');
    }

    $redirect_storage = $this->entityTypeManager->getStorage('redirect');
    $redirect = $redirect_storage->create();
    assert($redirect instanceof Redirect);
    $redirect->setSource('test-source');
    $redirect->setRedirect('node');
    $redirect->save();
    $restore_user = $this->createUser([
      'view any workspace',
      'restore redirect entities',
    ]);

    // A redirect trashed in a workspace keeps its hash in the base table until
    // the workspace is published, so a new redirect from the same source fails
    // validation there.
    $this->switchToWorkspace('stage');
    $redirect_storage->load($redirect->id())->delete();

    $new_redirect = $redirect_storage->create();
    assert($new_redirect instanceof Redirect);
    $new_redirect->setSource('test-source');
    $new_redirect->setRedirect('user');
    $violations = $new_redirect->validate()->getEntityViolations();
    $this->assertCount(1, $violations);
    $this->assertSame('The source path <em class="placeholder">test-source</em> is used by a redirect in the trash.', (string) $violations[0]->getMessage());

    // A user who can restore the trashed redirect gets a link to its restore
    // form. The link has the 'in_trash' query parameter, which loads the
    // trashed redirect in the 'ignore' trash context.
    $this->setCurrentUser($restore_user);
    $restore_url = $redirect->toUrl('restore');
    $this->assertStringContainsString('in_trash=1', $restore_url->toString());
    $this->assertTrue($this->getTrashManager()->executeInTrashContext('ignore', fn () => $restore_url->access()));
    $violations = $new_redirect->validate()->getEntityViolations();
    $this->assertCount(1, $violations);
    $this->assertSame('The source path <em class="placeholder">test-source</em> is used by a redirect in the trash. <a href="' . $restore_url->toString() . '">Restore it</a> to edit it.', (string) $violations[0]->getMessage());
  }

  /**
   * Drives the alias-flip scenario shared by both test methods above.
   */
  private function assertAliasFlipRestoresTrashedRedirect(): void {
    /** @var \Drupal\redirect\RedirectRepository $repository */
    $repository = \Drupal::service('redirect.repository');
    $redirect_storage = $this->entityTypeManager->getStorage('redirect');

    $node = Node::create([
      'type' => 'page',
      'title' => 'Test page',
      'path' => ['alias' => '/alpha'],
    ]);
    $node->save();
    $node_path = '/' . $node->toUrl()->getInternalPath();

    $this->assertEmpty($redirect_storage->getQuery()->accessCheck(FALSE)->execute(), 'No redirects exist after the initial alias is set.');

    // Changing the alias to /beta auto-creates a redirect from /alpha.
    $node->get('path')->alias = '/beta';
    $node->save();

    $alpha_redirects = $repository->findBySourcePath('alpha');
    $this->assertCount(1, $alpha_redirects, 'A redirect from /alpha to the node was created.');
    $alpha_redirect = reset($alpha_redirects);
    $this->assertEquals('internal:' . $node_path, $alpha_redirect->getRedirect()['uri']);

    // Give the redirect a non-default status code so we can later assert that
    // restoring it preserves its own settings instead of resetting them to the
    // 'default_status_code' a freshly auto-created redirect would receive.
    $alpha_redirect->setStatusCode(302);
    $alpha_redirect->save();

    // Changing the alias back to /alpha trashes the /alpha redirect (it is no
    // longer needed) and auto-creates a redirect from /beta instead.
    $node->get('path')->alias = '/alpha';
    $node->save();

    $this->assertEmpty($repository->findBySourcePath('alpha'), 'The /alpha redirect no longer resolves once trashed.');
    $this->assertNotEmpty($this->loadTrashedEntity('redirect', $alpha_redirect->id()), 'The /alpha redirect still exists in the trash.');

    $beta_redirects = $repository->findBySourcePath('beta');
    $this->assertCount(1, $beta_redirects, 'A redirect from /beta to the node was created.');
    $beta_redirect = reset($beta_redirects);

    // Changing the alias to /beta again trashes the /beta redirect and must
    // restore the /alpha redirect trashed by the first flip, since its
    // source path collides with a fresh redirect entity's default revision.
    $node->get('path')->alias = '/beta';
    $node->save();

    $this->assertEmpty($repository->findBySourcePath('beta'), 'The /beta redirect no longer resolves once trashed.');
    $this->assertNotEmpty($this->loadTrashedEntity('redirect', $beta_redirect->id()), 'The /beta redirect still exists in the trash.');

    $new_alpha_redirects = $repository->findBySourcePath('alpha');
    $this->assertCount(1, $new_alpha_redirects, 'The /alpha redirect resolves again.');
    $new_alpha_redirect = reset($new_alpha_redirects);
    assert($new_alpha_redirect instanceof Redirect);
    $this->assertEquals($alpha_redirect->id(), $new_alpha_redirect->id(), 'The trashed /alpha redirect was restored rather than a new entity being created.');
    $this->assertEquals('internal:' . $node_path, $new_alpha_redirect->getRedirect()['uri']);
    $this->assertEquals(302, (int) $new_alpha_redirect->getStatusCode(), 'The restored redirect keeps its own status code instead of resetting to the default.');
  }

}
