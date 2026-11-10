<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\trash\EntityHandler\TrashNodeAccessControlHandler;
use Drupal\trash\Hook\TrashEntityAccess;
use Drupal\trash\TrashStorageTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entity access for multilingual nodes with Content Translation enabled.
 *
 * Basically repeats the tests from MultilingualNodeAccessTest, but accounting
 * for additional permissions required by Content Translation.
 */
#[CoversClass(TrashEntityAccess::class)]
#[CoversClass(TrashNodeAccessControlHandler::class)]
#[CoversClass(TrashStorageTrait::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class ContentTranslationNodeAccessTest extends MultilingualNodeAccessTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')
      ->setDefaultLangcode('en')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public static function providerTestEntityAccess(): iterable {
    // Verify all operations on untranslated nodes are unaffected by enabling
    // Content Moderation.
    yield from NodeAccessTest::providerTestEntityAccess();

    // Verify almost all multilingual operations are also unaffected.
    foreach (parent::providerTestEntityAccess() as $description => $parameters) {
      // We should no longer be able to purge translations without the
      // relevant translation permissions required by Content Moderation.
      // Insert new tests which include the correct permissions and modify the
      // existing tests to not expect access.
      switch ($description) {
        case 'Deleted node translation with "purge node entities"':
        case 'Deleted node translation with "purge node translations"':
          // Add the required permissions to a new test.
          $new_parameters = $parameters;
          $new_parameters['permissions'][] = 'translate article node';
          $new_parameters['permissions'][] = 'delete content translations';
          yield $description . ', and Content Translation permissions' => $new_parameters;

          // Modify the old test to pass.
          $parameters['actions'][4][1]['purge'] = FALSE;
          break;

        case 'Deleted node translation with "restore node entities"':
        case 'Deleted node translation with "restore node translations"':
          // Add the required permissions to a new test.
          $new_parameters = $parameters;
          $new_parameters['permissions'][] = 'translate article node';
          $new_parameters['permissions'][] = 'update content translations';
          yield $description . ', and Content Translation permissions' => $new_parameters;

          // Modify the old test to pass.
          $parameters['actions'][4][1]['restore'] = FALSE;
          break;

        case 'Deleted node with "purge/restore node entities"':
        case 'Deleted node with "purge/restore node translations"':
          $new_parameters = $parameters;
          $new_parameters['permissions'][] = 'translate article node';
          $new_parameters['permissions'][] = 'update content translations';
          $new_parameters['permissions'][] = 'delete content translations';
          yield $description . ' and "translate article node" and "update/delete content translations"' => $new_parameters;

          $parameters['actions'][6][1]['restore'] = FALSE;
          $parameters['actions'][6][1]['purge'] = FALSE;
          $parameters['actions'][8][1]['restore'] = FALSE;
          $parameters['actions'][8][1]['purge'] = FALSE;
          break;

        case 'Non-deleted node where multiple translations were deleted simultaneously':
          $new_parameters = $parameters;
          $new_parameters['permissions'][] = 'translate article node';
          $new_parameters['permissions'][] = 'update content translations';
          $new_parameters['permissions'][] = 'delete content translations';
          yield $description . ' and "translate article node" and "update/delete content translations"' => $new_parameters;

          $parameters['actions'][4][1]['restore'] = FALSE;
          $parameters['actions'][4][1]['purge'] = FALSE;
          $parameters['actions'][6][1]['restore'] = FALSE;
          $parameters['actions'][6][1]['purge'] = FALSE;
          break;
      }

      yield $description => $parameters;
    }

    // Without 'update content translations', restore access falls back to
    // 'translate editable entities' plus update access to the entity itself.
    // The relaxed re-entrant update check used by that fallback must not be
    // replayed for the direct update/delete results asserted after it.
    yield 'Deleted node translation restored through "translate editable entities"' => [
      'permissions' => [
        'restore node translations',
        'translate editable entities',
        'edit any article content',
      ],
      'entity' => [
        'type' => 'node',
        'values' => [
          'type' => 'article',
          'langcode' => 'en',
          'title' => 'English title',
        ],
      ],
      'actions' => [
        [
          'add-translation',
          [
            'langcode' => 'sv',
            'values' => [
              // cspell:ignore Svensk Titel
              'title' => 'Svensk titel',
            ],
          ],
        ],
        ['delete-translation', 'sv'],
        ['switch-translation', 'sv'],
      ],
      'access_map' => [
        'restore' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'purge' => FALSE,
        'view' => FALSE,
      ],
    ];
  }

}
