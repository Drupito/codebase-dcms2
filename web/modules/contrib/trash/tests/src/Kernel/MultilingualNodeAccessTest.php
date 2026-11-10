<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\trash\EntityHandler\TrashNodeAccessControlHandler;
use Drupal\trash\Form\EntityPurgeForm;
use Drupal\trash\Form\EntityRestoreForm;
use Drupal\trash\Hook\TrashEntityAccess;
use Drupal\trash\ParamConverter\TrashEntityConverter;
use Drupal\trash\TrashStorageTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entity access for multilingual trashed entities.
 */
#[CoversClass(TrashEntityAccess::class)]
#[CoversClass(TrashNodeAccessControlHandler::class)]
#[CoversClass(EntityPurgeForm::class)]
#[CoversClass(EntityRestoreForm::class)]
#[CoversClass(TrashEntityConverter::class)]
#[CoversClass(TrashStorageTrait::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class MultilingualNodeAccessTest extends NodeAccessTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('sv')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
  }

  /**
   * Verifies the forbidden reasons reported for deleted translations.
   *
   * Also confirms anonymous and permission-less users are denied every
   * operation on a deleted translation.
   */
  public function testTranslationAccessReasons(): void {
    $this->getTrashManager()->setTrashContext('active');
    $account = $this->createUser(['access content', 'purge node entities']);
    $this->setCurrentUser($account);

    $context = ['entity' => $this->createEntity('node', ['type' => 'article', 'langcode' => 'en'])];
    $this->performAction(['add-translation', ['de', ['title' => $this->randomString()]]], $context);
    $this->performAction(['delete-translation', 'de'], $context);
    $this->performAction('reload-entity', $context);
    $this->performAction(['switch-translation', 'de'], $context);
    $german = $context['entity'];

    $this->assertSame('Translation is deleted', $german->access('update', $account, TRUE)->getReason());
    $this->assertSame("The 'restore node translations' permission is required.", $german->access('restore', $account, TRUE)->getReason());

    // Neither an anonymous nor a permission-less user can reach the deleted
    // translation through any operation.
    $anonymous = new AnonymousUserSession();
    $powerless = $this->createUser([]);
    foreach (['view', 'view label', 'update', 'delete', 'restore', 'purge'] as $operation) {
      $this->assertFalse($german->access($operation, $anonymous), "Anonymous must not be able to '$operation' a deleted translation.");
      $this->assertFalse($german->access($operation, $powerless), "A user without trash permissions must not be able to '$operation' a deleted translation.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function providerTestEntityAccess(): iterable {
    // Start with an English node that has Swedish and German translations.
    $article = [
      'type' => 'node',
      'values' => [
        'type' => 'article',
        'langcode' => 'en',
        'title' => 'English title',
      ],
    ];

    // Add Swedish and German translations, then delete the Swedish one.
    $deleted_translation_actions = [
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
      [
        'add-translation',
        [
          'langcode' => 'de',
          'values' => [
            // cspell:ignore Deutscher Titel
            'title' => 'Deutscher Titel',
          ],
        ],
      ],
      ['delete-translation', 'sv'],
      // Ensure the deleted swedish translation is active for the tests.
      ['switch-translation', 'sv'],
    ];

    yield 'Deleted node translation with "purge node entities"' => [
      'permissions' => ['purge node entities'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => FALSE,
            'purge' => TRUE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => TRUE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => FALSE,
            // German is not deleted.
            'purge' => FALSE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        // The default language is not deleted.
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node translation with "purge node translations"' => [
      'permissions' => ['purge node translations'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => FALSE,
            'purge' => TRUE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => TRUE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => FALSE,
            // German is not deleted.
            'purge' => FALSE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        // The default language is not deleted.
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node translation with "restore node entities"' => [
      'permissions' => ['restore node entities'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => FALSE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => TRUE,
            'update' => FALSE,
            'delete' => FALSE,
            // German is not deleted.
            'restore' => FALSE,
            'purge' => FALSE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        // The default language is not deleted.
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node translation with "restore node translations"' => [
      'permissions' => ['restore node translations'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => FALSE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => TRUE,
            'update' => FALSE,
            'delete' => FALSE,
            // German is not deleted.
            'restore' => FALSE,
            'purge' => FALSE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        // The default language is not deleted.
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    yield 'Deleted node with "purge/restore node entities"' => [
      'permissions' => ['purge node entities', 'restore node entities'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        // Fully delete the node and ensure only the expected results changed.
        'delete',
        ['switch-translation', 'sv'],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => TRUE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => TRUE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => TRUE,
        'purge' => TRUE,
      ],
    ];

    yield 'Deleted node with "purge/restore node translations"' => [
      'permissions' => ['purge node translations', 'restore node translations'],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        // Fully delete the node and ensure only the expected results changed.
        'delete',
        ['switch-translation', 'sv'],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            // The whole node is deleted, and the translation needs to also
            // restore the original language.
            'restore' => FALSE,
            'purge' => TRUE,
          ],
        ],
        [
          'switch-translation',
          'de',
        ],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => FALSE,
            'purge' => TRUE,
          ],
        ],
        ['switch-translation', 'en'],
      ]),
      'access_map' => [
        'view' => FALSE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    // The 'ignore' context (reachable request-wide via the 'in_trash' query
    // parameter for anyone with 'view deleted entities') must not open up
    // editing or deleting a trashed translation, even for a user whose regular
    // permissions would allow both.
    yield 'Deleted node translation with editor permissions and "ignore" context' => [
      'permissions' => [
        'view deleted entities',
        'edit any article content',
        'delete any article content',
      ],
      'entity' => $article,
      'actions' => array_merge($deleted_translation_actions, [
        ['set-trash-context', 'ignore'],
      ]),
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];

    // This is unusual, but possible to do programmatically.
    yield 'Non-deleted node where multiple translations were deleted simultaneously' => [
      'permissions' => ['purge node translations', 'restore node translations'],
      'entity' => $article,
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
        [
          'add-translation',
          [
            'langcode' => 'de',
            'values' => [
              // cspell:ignore Deutscher Titel
              'title' => 'Deutscher Titel',
            ],
          ],
        ],
        ['delete-translation', ['de', 'sv']],
        ['switch-translation', 'de'],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => TRUE,
          ],
        ],
        ['switch-translation', 'sv'],
        [
          'assert-operations-access',
          [
            'view' => FALSE,
            'update' => FALSE,
            'delete' => FALSE,
            'restore' => TRUE,
            'purge' => TRUE,
          ],
        ],
        ['switch-translation', 'en'],
      ],
      'access_map' => [
        'view' => TRUE,
        'update' => FALSE,
        'delete' => FALSE,
        'restore' => FALSE,
        'purge' => FALSE,
      ],
    ];
  }

}
