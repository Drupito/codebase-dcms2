<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\trash\EntityHandler\TrashNodeAccessControlHandler;
use Drupal\trash\Hook\TrashEntityAccess;
use Drupal\trash\TrashStorageTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entity access for trashed entities.
 */
#[CoversClass(TrashEntityAccess::class)]
#[CoversClass(TrashNodeAccessControlHandler::class)]
#[CoversClass(TrashStorageTrait::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class NodeAccessTest extends TrashKernelTestBase {

  use EntityAccessTestTrait;
  use EntityActionsTrait;

  /**
   * Verifies the forbidden reasons reported for trash access checks.
   *
   * The provider cases assert allowed/forbidden as booleans to keep the access
   * maps readable. This pins the specific reason string for each branch once,
   * and confirms anonymous and permission-less users are denied every operation
   * on a deleted entity.
   */
  public function testAccessReasons(): void {
    $this->getTrashManager()->setTrashContext('active');
    $restorer = $this->createUser(['access content', 'restore node entities']);
    $purger = $this->createUser(['access content', 'purge node entities']);
    $this->setCurrentUser($restorer);

    // Trash operations are never valid on a live entity.
    $live = $this->createEntity('node', ['type' => 'article']);
    $this->assertSame('Unsupported operation on non-deleted entity: restore', $this->accessReason($live, 'restore', $restorer));
    $this->assertSame('Unsupported operation on non-deleted entity: purge', $this->accessReason($live, 'purge', $restorer));

    // Restore and purge outcomes flip when trash support for the entity type
    // is toggled, so they must carry the trash.settings cacheability.
    $this->assertContains('config:trash.settings', $this->accessCacheTags($live, 'restore', $restorer));
    $this->assertContains('config:trash.settings', $this->accessCacheTags($live, 'purge', $purger));

    // A deleted entity blocks other operations and reports the missing
    // restore/purge permissions.
    $context = ['entity' => $this->createEntity('node', ['type' => 'article'])];
    $this->performAction('delete', $context);
    $node = $context['entity'];
    $this->assertSame('Entity is deleted', $this->accessReason($node, 'update', $restorer));
    $this->assertSame("The 'purge node entities' permission is required.", $this->accessReason($node, 'purge', $restorer));
    $this->assertSame("The 'restore node entities' permission is required.", $this->accessReason($node, 'restore', $purger));
    $this->assertContains('config:trash.settings', $this->accessCacheTags($node, 'restore', $restorer));
    $this->assertContains('config:trash.settings', $this->accessCacheTags($node, 'purge', $purger));

    // Neither an anonymous nor a permission-less user can reach the deleted
    // entity through any operation.
    $anonymous = new AnonymousUserSession();
    $powerless = $this->createUser([]);
    foreach (['view', 'view label', 'update', 'delete', 'restore', 'purge'] as $operation) {
      $this->assertFalse($node->access($operation, $anonymous), "Anonymous must not be able to '$operation' a deleted entity.");
      $this->assertFalse($node->access($operation, $powerless), "A user without trash permissions must not be able to '$operation' a deleted entity.");
    }

    // A deleted revision that is neither the default nor the latest cannot be
    // reverted to, even with permission to edit and revert (needed so the node
    // access handler does not forbid first with its own reason).
    $reverter = $this->createUser(['access content', 'edit any article content', 'revert all revisions']);
    $context = ['entity' => $this->createEntity('node', ['type' => 'article'])];
    $this->performAction('delete', $context);
    $this->performAction('restore', $context);
    $this->performAction(['create-revision', TRUE], $context);
    $this->performAction('save', $context);
    $this->performAction(['set-trash-context', 'ignore'], $context);
    $this->performAction(['load-nth-revision', 2], $context);
    $this->assertSame('Can not revert to a deleted revision', $this->accessReason($context['entity'], 'revert revision', $reverter));
  }

  /**
   * Returns the access result reason for an operation.
   */
  private function accessReason(EntityInterface $entity, string $operation, AccountInterface $account): ?string {
    $access = $entity->access($operation, $account, TRUE);
    assert($access instanceof AccessResultReasonInterface);
    return $access->getReason();
  }

  /**
   * Returns the access result cache tags for an operation.
   */
  private function accessCacheTags(EntityInterface $entity, string $operation, AccountInterface $account): array {
    $access = $entity->access($operation, $account, TRUE);
    assert($access instanceof AccessResult);
    return $access->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public static function providerTestEntityAccess(): iterable {
    $article = ['type' => 'node', 'values' => ['type' => 'article']];

    yield 'Deleted node with "access content" and "active" context' => [
      'permissions' => ['access content'],
      'entity' => $article,
      'actions' => [
        'delete',
        ['set-trash-context', 'active'],
      ],
      'access_map' => [
        'view' => FALSE,
        'view label' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "access content" and "inactive" context' => [
      'permissions' => ['access content'],
      'entity' => $article,
      'actions' => [
        'delete',
        ['set-trash-context', 'inactive'],
      ],
      'access_map' => [
        'view' => FALSE,
        'view label' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "access content" and "ignore" context' => [
      'permissions' => ['access content'],
      'entity' => $article,
      'actions' => [
        'delete',
        ['set-trash-context', 'ignore'],
      ],
      'access_map' => [
        'view' => FALSE,
        'view label' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    // The 'ignore' context (reachable request-wide via the 'in_trash' query
    // parameter for anyone with 'view deleted entities') must not open up
    // editing or deleting a trashed entity, even for a user whose regular
    // permissions would allow both.
    yield 'Deleted node with editor permissions and "ignore" context' => [
      'permissions' => [
        'view deleted entities',
        'edit any article content',
        'delete any article content',
      ],
      'entity' => $article,
      'actions' => [
        'delete',
        ['set-trash-context', 'ignore'],
      ],
      'access_map' => [
        'view' => TRUE,
        'view label' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "bypass node access"' => [
      'permissions' => ['bypass node access'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "edit any article content"' => [
      'permissions' => ['edit any article content'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "delete any article content"' => [
      'permissions' => ['delete any article content'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "view deleted entities"' => [
      'permissions' => ['view deleted entities'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "restore node entities"' => [
      'permissions' => ['restore node entities'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => TRUE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "restore node translations"' => [
      'permissions' => ['restore node translations'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "purge node entities"' => [
      'permissions' => ['purge node entities'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => TRUE,
      ],
    ];

    yield 'Deleted node with "purge node translations"' => [
      'permissions' => ['purge node translations'],
      'entity' => $article,
      'actions' => ['delete'],
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Non-deleted node with "bypass node access"' => [
      'permissions' => ['bypass node access'],
      'entity' => $article,
      'actions' => [],
      'access_map' => [
        'view' => TRUE,
        'update' => TRUE,
        'delete' => TRUE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Non-deleted node with full view, edit and delete permissions.' => [
      'permissions' => ['edit any article content', 'delete any article content'],
      'entity' => $article,
      'actions' => [],
      'access_map' => [
        'view' => TRUE,
        'update' => TRUE,
        'delete' => TRUE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    // Reverting a revision which was marked deleted is prevented because Core
    // sets the new revision as default, which would effectively mark the entity
    // deleted again, likely causing some confusion.
    yield 'Restored node with deleted revisions' => [
      'permissions' => [
        'edit any article content',
        'delete any article content',
        'revert all revisions',
        'delete all revisions',
        'purge node entities',
        'purge node translations',
      ],
      'entity' => $article,
      'actions' => [
        // Creates revision 2.
        'delete',
        // Creates revision 3.
        'restore',
        // Begin creating revision 4, which will become the new default.
        ['create-revision', TRUE],
        // Make an actual change.
        ['set-values', ['title' => 'New revision']],
        // Creates revision 4.
        'save',
        // Need to be able to load deleted revisions.
        ['set-trash-context', 'ignore'],
        ['load-nth-revision', 2],
        [
          'assert-operations-access',
          [
            'delete revision' => TRUE,
            'revert revision' => FALSE,
          ],
        ],
        ['load-nth-revision', 3],
        // Verify normal access to the older revision which is not deleted.
        [
          'assert-operations-access',
          [
            'view' => TRUE,
            'update' => TRUE,
            'delete' => TRUE,
            'restore' => FALSE,
            'purge' => FALSE,
            'delete revision' => TRUE,
            'revert revision' => TRUE,
          ],
        ],
        ['load-nth-revision', 4],
      ],
      // Verify we block access to reverting to the deleted revision.
      'access_map' => [
        'view' => TRUE,
        'update' => TRUE,
        'delete' => TRUE,
        'restore' => FALSE,
        'purge' => FALSE,
        // Revision deletion is blocked because it's the default revision.
        'delete revision' => FALSE,
        // Disallowed because the new revision would make the node deleted.
        'revert revision' => FALSE,
      ],
    ];

    // Verify that pending revisions are ignored when deleting and restoring.
    yield 'Deleting and restoring a node with pending revision' => [
      // This really tests programmatic behavior, not permissions.
      'permissions' => [],
      'entity' => $article,
      'actions' => [
        ['set-values', ['title' => 'Initial revision']],
        'save',

        // Create a new revision which will become pending (non-default).
        ['create-revision', FALSE],
        ['set-values', ['title' => 'New pending revision']],
        'save',
        // Verify the revisions are different.
        'load-canonical',
        ['assert-values', ['title' => [0 => ['value' => 'Initial revision']]]],
        ['load-active'],
        ['assert-values', ['title' => [0 => ['value' => 'New pending revision']]]],

        // Delete the node, creating a new revision.
        'delete',
        // Assert it used the default revision as base for the deleted revision.
        ['set-trash-context', 'ignore'],
        ['load-active'],
        ['assert-values', ['title' => [0 => ['value' => 'Initial revision']]]],

        // Modify the deleted node so we can test restoring also works the same.
        ['set-values', ['title' => 'Modified deleted revision']],
        'save',

        // Create another new pending revision from the deleted contents.
        ['create-revision', FALSE],
        ['set-values', ['title' => 'Deleted new pending revision']],
        'save',

        // Restore the node and assert it's still using the previous title.
        'restore',
        ['set-trash-context', 'active'],
        'load-active',
        ['assert-values', ['title' => [0 => ['value' => 'Modified deleted revision']]]],

        // Assert this wasn't a false positive due to inactive trash context.
        'load-active',
        ['assert-values', ['title' => [0 => ['value' => 'Modified deleted revision']]]],
      ],
      // No need to test any other permissions.
      'access_map' => [],
    ];

  }

}
