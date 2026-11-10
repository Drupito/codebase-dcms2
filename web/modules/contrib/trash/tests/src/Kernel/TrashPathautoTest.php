<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\node\Entity\Node;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\trash\Handler\DefaultTrashHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that trash operations suppress pathauto alias generation.
 */
#[CoversClass(DefaultTrashHandler::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashPathautoTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path',
    'path_alias',
    'token',
    'pathauto',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = ['path_alias' => []];

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('path_alias');
    // Token replacement in pathauto patterns needs the default date formats.
    $this->installConfig(['system', 'pathauto']);

    PathautoPattern::create([
      'id' => 'node',
      'label' => 'Node',
      'type' => 'canonical_entities:node',
      'pattern' => '/content/[node:title]',
    ])->save();
  }

  /**
   * Restoring a pathauto-aliased entity must not create a duplicate alias.
   *
   * Pathauto fires on the restore save, before postTrashRestore() brings the
   * original alias back. If it were not suppressed it would generate a second
   * live alias for the same source.
   */
  public function testPathautoAliasNotDuplicatedOnRestore(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Foo']);
    $source = '/node/' . $node->id();

    // Pathauto created one live alias on insert.
    $this->assertSame(1, $this->countLiveAliases($source));

    // Trashing removes the live alias (it is trashed, not regenerated).
    $node->delete();
    $this->assertNull(Node::load($node->id()));
    $this->assertSame(0, $this->countLiveAliases($source));

    // Restoring brings back exactly one live alias.
    $this->restoreEntity('node', $node->id());
    $this->assertNotNull(Node::load($node->id()));
    $this->assertSame(1, $this->countLiveAliases($source));
  }

  /**
   * Counts the live (non-trashed) path aliases for a source path.
   */
  private function countLiveAliases(string $source): int {
    return (int) $this->entityTypeManager->getStorage('path_alias')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', $source)
      ->count()
      ->execute();
  }

}
