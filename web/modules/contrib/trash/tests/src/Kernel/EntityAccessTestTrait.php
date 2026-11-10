<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Provides standardized test verifying entities access after some actions.
 *
 * @see \Drupal\Tests\trash\Kernel\EntityActionsTrait
 *
 * @phpstan-require-extends \Drupal\Tests\trash\Kernel\TrashKernelTestBase
 */
trait EntityAccessTestTrait {

  /**
   * Tests entity access for entities.
   *
   * Iterates over all cases from ::providerTestEntityAccess() in a single test
   * method, avoiding the overhead of repeated setUp() per data provider case.
   */
  public function testEntityAccess(): void {
    foreach (static::providerTestEntityAccess() as $case_name => $case) {
      $permissions = $case['permissions'];
      $entity_definition = $case['entity'];
      $actions = $case['actions'];
      $access_map = $case['access_map'] ?? NULL;

      // Reset trash context between cases.
      $this->getTrashManager()->setTrashContext('active');

      $account = $this->createUser(array_merge([
        'access content',
      ], $permissions));

      $this->setCurrentUser($account);

      $entity = $this->createEntity($entity_definition['type'], $entity_definition['values'] ?? []);
      $context = ['entity' => &$entity];
      $step = 0;
      $last_action = NULL;

      try {
        foreach ($actions as $action) {
          $last_action = \is_array($action) ? $action[0] : $action;
          $this->performAction($action, $context);
          $step++;
        }

        if ($access_map) {
          $last_action = 'assert-operations-access';
          $this->performAction([$last_action, $access_map], $context);
        }
      }
      // AssertionFailedError is @internal but has no public equivalent;
      // catching it to re-throw assertion failures keeps their diff intact.
      // @phpstan-ignore catch.internalClass
      catch (AssertionFailedError $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        // Wrap only real errors (not failed assertions) with case context.
        $this->fail("[$case_name] action '$last_action' ($step): " . $e->getMessage());
      }
    }
  }

  /**
   * Using classes should implement this to provide test cases.
   *
   * The test case format:
   *  - 'permissions': Array of permissions the current user needs for the test.
   *    'access content' is always included.
   *  - 'entity': Array with the keys:
   *    - 'type': The entity type to create at the start of the test.
   *    - 'values' The field values to pass when creating the entity.
   *  - 'actions' => An array of actions to perform. Each entry is a string with
   *    just an action name, or an array where the first value is the action
   *    name and the second value the argument(s) to pass to the action.
   *    See EntityActionsTrait for available actions. Each action also has
   *    access to a 'context' array where 'entity' is the actual created entity.
   *    Actions are assumed to do something with this entity, and keep that
   *    reference valid with the latest instance of that entity whenever
   *    possible. Actions may also put other data in the context for use by
   *    subsequent actions, much like the sandbox in Batch API.
   *  - 'access_map': Many tests perform asserts on entity operations at the end
   *    and this is a map of the operation name to test and the expected result.
   *    The result may be a boolean or an AccessResultInterface. If the access
   *    result has a 'reason' string, it is also compared, else it is only
   *    asserted that the object type is the same.
   *    There is also an 'assert-operations-access' action for tests needing
   *    to perform assertions part way through the test.
   *
   * @return iterable<string, array{permissions: string[], entity: array{type: string, values?: array<string, mixed>}, actions: array<mixed>, access_map?: array<string, bool|\Drupal\Core\Access\AccessResultInterface>}>
   *   Test cases in the format described above.
   */
  abstract public static function providerTestEntityAccess(): iterable;

}
