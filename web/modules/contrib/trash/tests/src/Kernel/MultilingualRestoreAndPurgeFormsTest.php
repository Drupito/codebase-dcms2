<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\NodeInterface;
use Drupal\trash\Form\EntityPurgeMultipleForm;
use Drupal\trash\Form\EntityRestoreForm;
use Drupal\trash\Form\EntityRestoreMultipleForm;
use Drupal\trash\Trash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the restore and purge forms with multilingual entities.
 */
#[CoversClass(EntityPurgeMultipleForm::class)]
#[CoversClass(EntityRestoreForm::class)]
#[CoversClass(EntityRestoreMultipleForm::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class MultilingualRestoreAndPurgeFormsTest extends TrashKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'content_translation',
  ];

  /**
   * A user who can restore entities and their translations.
   */
  protected AccountInterface $restorer;

  /**
   * A user who can restore entities but not individual translations.
   */
  protected AccountInterface $limitedRestorer;

  /**
   * A user who can purge entities and their translations.
   */
  protected AccountInterface $purger;

  /**
   * A user who can purge entities but not individual translations.
   */
  protected AccountInterface $limitedPurger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('sv')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')
      ->setDefaultLangcode('en')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();

    $this->restorer = $this->createUser([
      'access content',
      'restore node entities',
      'translate article node',
      'update content translations',
    ]);
    $this->limitedRestorer = $this->createUser([
      'access content',
      'restore node entities',
    ]);
    $this->purger = $this->createUser([
      'access content',
      'purge node entities',
      'translate article node',
      'delete content translations',
    ]);
    $this->limitedPurger = $this->createUser([
      'access content',
      'purge node entities',
    ]);
  }

  /**
   * Tests that the bulk restore form restores exactly the selected rows.
   */
  public function testBulkRestoreForm(): void {
    $this->setCurrentUser($this->restorer);

    // Selecting only the default-translation row of a fully-trashed entity
    // restores only the default translation, and the confirm page shows just
    // that row instead of promising all translations.
    $node = $this->createTranslatedNode('First');
    $node->delete();
    $this->seedSelection('trash_restore_multiple_confirm', [$node->id() => ['en' => 'en']]);
    $items = $this->buildBulkForm(EntityRestoreMultipleForm::class)['entities']['#items'];
    $this->assertCount(1, $items);
    $this->assertSame('First EN', (string) $items[$node->id()]);
    $this->submitBulkForm(EntityRestoreMultipleForm::class);
    $this->assertMessages(['Restored 1 item from trash.'], []);
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => TRUE, 'de' => TRUE]);

    // Selecting a non-default translation whose default translation is also
    // trashed restores the default too: the confirm page discloses it and the
    // success count includes it.
    $node = $this->createTranslatedNode('Second');
    $node->delete();
    $this->seedSelection('trash_restore_multiple_confirm', [$node->id() => ['sv' => 'sv']]);
    $items = $this->buildBulkForm(EntityRestoreMultipleForm::class)['entities']['#items'];
    $this->assertCount(1, $items);
    $this->assertStringContainsString('The following content item translations will be restored:', (string) $items[$node->id()]['label']['#markup']);
    $names = array_map('strval', $items[$node->id()]['restored_translations']['#items']);
    $this->assertSame(['Swedish', 'English (Original language)'], $names);
    $this->submitBulkForm(EntityRestoreMultipleForm::class);
    $this->assertMessages(['Restored 2 items from trash.'], []);
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => FALSE, 'de' => TRUE]);

    // A mixed selection where one row is no longer deleted: the stale row is
    // ignored on the confirm page, in the success count, and it does not
    // produce a bogus permissions warning.
    $node = $this->createTranslatedNode('Third');
    $node->removeTranslation('sv');
    $node->removeTranslation('de');
    $node->save();
    $this->seedSelection('trash_restore_multiple_confirm', [$node->id() => ['en' => 'en', 'sv' => 'sv', 'de' => 'de']]);
    $items = $this->buildBulkForm(EntityRestoreMultipleForm::class)['entities']['#items'];
    $this->assertCount(1, $items);
    $names = array_map('strval', $items[$node->id()]['restored_translations']['#items']);
    $this->assertSame(['Swedish', 'German'], $names);
    $this->submitBulkForm(EntityRestoreMultipleForm::class);
    $this->assertMessages(['Restored 2 items from trash.'], []);
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => FALSE, 'de' => FALSE]);

    // Without translation permissions, restoring a non-default translation
    // row is denied and reported, and nothing is restored.
    $this->setCurrentUser($this->limitedRestorer);
    $node = $this->createTranslatedNode('Fourth');
    $node->delete();
    $this->seedSelection('trash_restore_multiple_confirm', [$node->id() => ['sv' => 'sv']]);
    $this->submitBulkForm(EntityRestoreMultipleForm::class);
    $this->assertMessages([], ['1 item has not been restored because you do not have the necessary permissions.']);
    $this->assertTranslationDeletionState($node->id(), ['en' => TRUE, 'sv' => TRUE, 'de' => TRUE]);

    // The same user may still restore via the default-translation row.
    $this->seedSelection('trash_restore_multiple_confirm', [$node->id() => ['en' => 'en']]);
    $this->submitBulkForm(EntityRestoreMultipleForm::class);
    $this->assertMessages(['Restored 1 item from trash.'], []);
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => TRUE, 'de' => TRUE]);
  }

  /**
   * Tests per-translation access checks on the single restore form.
   */
  public function testSingleRestoreForm(): void {
    $this->setCurrentUser($this->limitedRestorer);
    $node = $this->createTranslatedNode('Solo');
    $node->delete();
    $node = $this->loadTrashedEntity('node', $node->id());

    // Without translation permissions, no co-restore checkboxes are offered.
    $form = $this->buildSingleRestoreForm($node);
    $this->assertArrayNotHasKey('translations', $form);

    // A user with translation permissions gets all deleted translations
    // offered, pre-checked when restoring the default translation.
    $this->setCurrentUser($this->restorer);
    $form = $this->buildSingleRestoreForm($node);
    $this->assertSame(['de', 'sv'], array_keys($form['translations']['#options']));
    $this->assertSame(['de', 'sv'], $form['translations']['#default_value']);

    // Checked langcodes are access-checked again on submit. Call the submit
    // handler directly with checked translations while acting as the limited
    // user: this simulates access getting revoked after the form was built,
    // which a full form submission cannot reproduce because rebuilding the
    // form drops the checkboxes along with their input.
    $this->setCurrentUser($this->limitedRestorer);
    $form_object = $this->getEntityTypeManager()->getFormObject('node', 'restore');
    $form_object->setEntity($node);
    $form_state = new FormState();
    $form_state->setValue('translations', ['sv' => 'sv', 'de' => 'de']);
    $form = [];
    $this->getTrashManager()->executeInTrashContext('ignore',
      fn () => $form_object->submitForm($form, $form_state)
    );
    $messenger = $this->container->get('messenger');
    $warnings = array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_WARNING));
    $this->assertSame([
      'The Swedish translation has not been restored because you do not have the necessary permissions.',
      'The German translation has not been restored because you do not have the necessary permissions.',
    ], $warnings);
    $status = array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_STATUS));
    $this->assertCount(1, $status);
    $this->assertStringContainsString('has been restored from trash.', $status[0]);
    $messenger->deleteAll();
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => TRUE, 'de' => TRUE]);
  }

  /**
   * Tests warnings and counts on the bulk purge form.
   */
  public function testBulkPurgeForm(): void {
    $this->setCurrentUser($this->limitedPurger);

    // Purging via the default-translation row destroys the whole entity, as
    // the confirm page promises. A sibling translation whose own access check
    // failed is collateral of that purge, so no permissions warning is shown.
    $node = $this->createTranslatedNode('First');
    $node->delete();
    $this->seedSelection('trash_purge_multiple_confirm', [$node->id() => ['en' => 'en', 'sv' => 'sv']]);
    $items = $this->buildBulkForm(EntityPurgeMultipleForm::class)['entities']['#items'];
    $names = array_map('strval', $items[$node->id() . ':en']['deleted_translations']['#items']);
    $this->assertSame(['English', 'German', 'Swedish'], $names);
    $this->submitBulkForm(EntityPurgeMultipleForm::class);
    $this->assertMessages(['Permanently deleted 3 items.'], []);
    $this->assertNull($this->loadTrashedEntity('node', $node->id()));

    // A selected translation the user cannot purge, with no whole-entity
    // purge involved, is reported and kept.
    $node = $this->createTranslatedNode('Second');
    $node->removeTranslation('sv');
    $node->save();
    $this->seedSelection('trash_purge_multiple_confirm', [$node->id() => ['sv' => 'sv']]);
    $this->submitBulkForm(EntityPurgeMultipleForm::class);
    $this->assertMessages([], ['1 item has not been deleted because you do not have the necessary permissions.']);
    $this->assertTranslationDeletionState($node->id(), ['en' => FALSE, 'sv' => TRUE]);

    // With translation permissions, purging translation rows removes only
    // those translations and counts them individually.
    $this->setCurrentUser($this->purger);
    $node = $this->createTranslatedNode('Third');
    $node->delete();
    $this->seedSelection('trash_purge_multiple_confirm', [$node->id() => ['sv' => 'sv', 'de' => 'de']]);
    $this->submitBulkForm(EntityPurgeMultipleForm::class);
    $this->assertMessages(['Permanently deleted 2 items.'], []);
    $node = $this->loadTrashedEntity('node', $node->id());
    $this->assertNotNull($node);
    $this->assertFalse($node->hasTranslation('sv'));
    $this->assertFalse($node->hasTranslation('de'));
    $this->assertTrue(Trash::entityIsDeleted($node));
  }

  /**
   * Creates an article with an English default and sv/de translations.
   */
  protected function createTranslatedNode(string $label_prefix): NodeInterface {
    $node = $this->createNode([
      'type' => 'article',
      'langcode' => 'en',
      'title' => $label_prefix . ' EN',
    ]);
    $node->addTranslation('sv', ['title' => $label_prefix . ' SV']);
    $node->addTranslation('de', ['title' => $label_prefix . ' DE']);
    $node->save();
    return $node;
  }

  /**
   * Seeds the bulk-form tempstore selection for the current user.
   */
  protected function seedSelection(string $collection, array $selection): void {
    $this->container->get('tempstore.private')
      ->get($collection)
      ->set($this->container->get('current_user')->id() . ':node', $selection);
  }

  /**
   * Builds a bulk confirm form for nodes and returns the form array.
   */
  protected function buildBulkForm(string $form_class): array {
    return $this->getTrashManager()->executeInTrashContext('ignore',
      fn () => $this->container->get('form_builder')->getForm($form_class, 'node')
    );
  }

  /**
   * Submits a bulk confirm form for nodes.
   */
  protected function submitBulkForm(string $form_class): void {
    $form_state = new FormState();
    $this->getTrashManager()->executeInTrashContext('ignore',
      fn () => $this->container->get('form_builder')->submitForm($form_class, $form_state, 'node')
    );
  }

  /**
   * Builds the single restore form for the given entity.
   */
  protected function buildSingleRestoreForm(NodeInterface $node): array {
    $form_object = $this->getEntityTypeManager()->getFormObject('node', 'restore');
    $form_object->setEntity($node);
    $form_state = new FormState();
    return $this->getTrashManager()->executeInTrashContext('ignore',
      fn () => $this->container->get('form_builder')->buildForm($form_object, $form_state)
    );
  }

  /**
   * Asserts the status and warning messages, then clears the messenger.
   */
  protected function assertMessages(array $expected_status, array $expected_warnings): void {
    $messenger = $this->container->get('messenger');
    $this->assertSame($expected_status, array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_STATUS)));
    $this->assertSame($expected_warnings, array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_WARNING)));
    $messenger->deleteAll();
  }

  /**
   * Asserts the soft-deleted state of each translation directly in storage.
   *
   * @param int|string $nid
   *   The node ID.
   * @param array<string, bool> $expected
   *   Whether each langcode is expected to be soft-deleted, keyed by langcode.
   */
  protected function assertTranslationDeletionState(int|string $nid, array $expected): void {
    $this->getEntityTypeManager()->getStorage('node')->resetCache([$nid]);
    $node = $this->loadTrashedEntity('node', $nid);
    $this->assertNotNull($node);
    foreach ($expected as $langcode => $is_deleted) {
      $this->assertTrue($node->hasTranslation($langcode), "Translation '$langcode' exists.");
      $this->assertSame($is_deleted, Trash::entityIsDeleted($node->getTranslation($langcode)), "Translation '$langcode' soft-deleted state.");
    }
  }

}
