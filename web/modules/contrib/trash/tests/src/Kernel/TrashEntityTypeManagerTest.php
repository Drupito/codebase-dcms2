<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Plugin\CachedDiscoveryClearer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\trash\TrashEntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the 'entity_type.manager' decorator.
 */
#[CoversClass(TrashEntityTypeManager::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashEntityTypeManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'trash',
    'user',
  ];

  /**
   * Tests that the plugin cache clearer clears entity types first.
   *
   * @see https://www.drupal.org/node/3624638
   */
  public function testClearedFirst(): void {
    $clearer = $this->container->get('plugin.cache_clearer');
    $discoveries = (new \ReflectionProperty(CachedDiscoveryClearer::class, 'cachedDiscoveries'))->getValue($clearer);
    static::assertInstanceOf(TrashEntityTypeManager::class, iterator_to_array($discoveries, FALSE)[0]);
  }

}
