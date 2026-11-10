<?php

declare(strict_types=1);

namespace Drupal\Tests\trash\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use Drupal\trash\Hook\TrashHandler\TaxonomyTermTrashHandler;
use Drupal\trash\Trash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests trash functionality for taxonomy terms.
 */
#[CoversClass(TaxonomyTermTrashHandler::class)]
#[Group('trash')]
#[RunTestsInSeparateProcesses]
class TrashTaxonomyTermTest extends TrashKernelTestBase {

  use TaxonomyTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected array $additionalTrashEntityTypes = ['taxonomy_term' => []];

  /**
   * The test vocabulary.
   */
  protected VocabularyInterface $vocabulary;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->vocabulary = $this->createVocabulary();
  }

  /**
   * {@inheritdoc}
   */
  protected function installAdditionalEntitySchemas(): void {
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['taxonomy']);
  }

  /**
   * Tests cascade trash and restore across single-parent and deep hierarchies.
   *
   * Covers cascade in both directions, hierarchy preservation and tree
   * visibility on restore, and restoring a child on its own while its parent
   * stays trashed (the child moves to the tree root).
   */
  public function testHierarchyCascade(): void {
    $term_storage = $this->getEntityTypeManager()->getStorage('taxonomy_term');

    // Single parent with two children: trashing the parent cascade-trashes
    // them, restoring it cascade-restores them.
    $parent = $this->createTerm($this->vocabulary, ['name' => 'Parent']);
    $child1 = $this->createTerm($this->vocabulary, ['name' => 'Child 1', 'parent' => $parent->id()]);
    $child2 = $this->createTerm($this->vocabulary, ['name' => 'Child 2', 'parent' => $parent->id()]);

    $parent->delete();
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $parent->id())));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $child1->id())));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $child2->id())));

    $this->restoreEntity('taxonomy_term', $parent->id());
    $this->assertNotEmpty(Term::load($parent->id()));
    $this->assertNotEmpty(Term::load($child1->id()));
    $this->assertNotEmpty(Term::load($child2->id()));

    // A child can be restored on its own while the parent stays trashed; it
    // appears in the taxonomy tree, moved to the root.
    $parent->delete();
    $this->restoreEntity('taxonomy_term', $child1->id());
    $this->assertFalse(Trash::entityIsDeleted(Term::load($child1->id())));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $parent->id())));
    $this->assertContains($child1->id(), array_column($term_storage->loadTree($this->vocabulary->id()), 'tid'));

    // Deep hierarchy: trashing the grandparent cascades all the way down, and
    // restoring it cascade-restores while preserving the parent references and
    // tree visibility.
    $grandparent = $this->createTerm($this->vocabulary, ['name' => 'Grandparent']);
    $deep_parent = $this->createTerm($this->vocabulary, ['name' => 'Deep parent', 'parent' => $grandparent->id()]);
    $deep_child = $this->createTerm($this->vocabulary, ['name' => 'Deep child', 'parent' => $deep_parent->id()]);

    $grandparent->delete();
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $grandparent->id())));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $deep_parent->id())));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $deep_child->id())));

    $this->restoreEntity('taxonomy_term', $grandparent->id());
    $this->assertNotEmpty(Term::load($grandparent->id()));
    $this->assertNotEmpty(Term::load($deep_parent->id()));
    $this->assertNotEmpty(Term::load($deep_child->id()));
    $this->assertContains($grandparent->id(), $this->getParentIds(Term::load($deep_parent->id())));
    $this->assertContains($deep_parent->id(), $this->getParentIds(Term::load($deep_child->id())));

    $tree_tids = array_column($term_storage->loadTree($this->vocabulary->id()), 'tid');
    $this->assertContains($grandparent->id(), $tree_tids);
    $this->assertContains($deep_parent->id(), $tree_tids);
    $this->assertContains($deep_child->id(), $tree_tids);
  }

  /**
   * Tests translation-aware cascade delete and restore.
   *
   * The term hierarchy is not language-aware, so: removing one parent
   * translation does not cascade; a partial restore of the parent brings the
   * whole cascaded child back once the default translation returns; and a child
   * translation trashed on its own (a different timestamp) is left in trash.
   */
  public function testTranslationAwareCascade(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

    // Removing one parent translation keeps it a live node, so the child must
    // not be cascade-trashed.
    $parent = $this->createTerm($this->vocabulary, ['name' => 'Parent', 'langcode' => 'en']);
    $parent->addTranslation('de', ['name' => 'Parent DE']);
    $parent->save();
    $child = $this->createTerm($this->vocabulary, ['name' => 'Child', 'parent' => $parent->id()]);
    $parent = Term::load($parent->id());
    $parent->removeTranslation('de');
    $parent->save();
    $this->assertNotEmpty(Term::load($parent->id()));
    $this->assertFalse(Trash::entityIsDeleted(Term::load($child->id())));

    // Trash a whole multilingual hierarchy in one operation (so every
    // translation shares the cascade timestamp), then restore only the parent's
    // FR translation. That force-includes the deleted default (EN), which makes
    // the parent a live node again and restores the whole cascaded child,
    // including the DE translation the parent has not restored yet.
    $full_parent = $this->createTerm($this->vocabulary, ['name' => 'Full parent', 'langcode' => 'en']);
    $full_parent->addTranslation('fr', ['name' => 'Full parent FR']);
    $full_parent->addTranslation('de', ['name' => 'Full parent DE']);
    $full_parent->save();
    $full_child = $this->createTerm($this->vocabulary, [
      'name' => 'Full child',
      'parent' => $full_parent->id(),
      'langcode' => 'en',
    ]);
    $full_child->addTranslation('fr', ['name' => 'Full child FR']);
    $full_child->addTranslation('de', ['name' => 'Full child DE']);
    $full_child->save();
    Term::load($full_parent->id())->delete();
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $full_child->id())));
    $this->restoreEntity('taxonomy_term', $full_parent->id(), ['fr']);
    $full_child = Term::load($full_child->id());
    $this->assertNotEmpty($full_child);
    $this->assertEmpty($full_child->getTranslation('en')->get('deleted')->value);
    $this->assertEmpty($full_child->getTranslation('fr')->get('deleted')->value);
    $this->assertEmpty($full_child->getTranslation('de')->get('deleted')->value);
    // The parent's own DE translation is still trashed.
    $this->assertNotEmpty($this->loadTrashedEntity('taxonomy_term', $full_parent->id())->getTranslation('de')->get('deleted')->value);

    // A child translation removed on its own before the cascade has a different
    // timestamp, so restoring the parent leaves it in the trash.
    $solo_parent = $this->createTerm($this->vocabulary, ['name' => 'Solo parent', 'langcode' => 'en']);
    $solo_child = $this->createTerm($this->vocabulary, [
      'name' => 'Solo child',
      'parent' => $solo_parent->id(),
      'langcode' => 'en',
    ]);
    $solo_child->addTranslation('de', ['name' => 'Solo child DE']);
    $solo_child->save();
    $solo_child = Term::load($solo_child->id());
    $solo_child->removeTranslation('de');
    $solo_child->save();
    $solo_trashed = $this->loadTrashedEntity('taxonomy_term', $solo_child->id());
    $this->assertNotEmpty($solo_trashed->getTranslation('de')->get('deleted')->value);
    $this->assertEmpty($solo_trashed->getTranslation('en')->get('deleted')->value);
    // Later, trash the parent, cascading only the child's still-live EN.
    \Drupal::keyValue('trash_test')->set('time_offset', 100);
    Term::load($solo_parent->id())->delete();
    $this->assertNotEmpty($this->loadTrashedEntity('taxonomy_term', $solo_child->id())->getTranslation('en')->get('deleted')->value);
    // Restoring the parent restores the cascaded EN but leaves the
    // independently trashed DE in the trash.
    $this->restoreEntity('taxonomy_term', $solo_parent->id());
    $solo_child = Term::load($solo_child->id());
    $this->assertNotEmpty($solo_child);
    $this->assertEmpty($solo_child->get('deleted')->value);
    $this->assertNotEmpty($this->loadTrashedEntity('taxonomy_term', $solo_child->id())->getTranslation('de')->get('deleted')->value);
  }

  /**
   * Tests multi-parent cascade and restore scoping by deleted timestamp.
   *
   * A child stays active while any parent is live and is cascade-trashed only
   * once all parents are gone. On restore, only children with a matching
   * deleted timestamp come back: an independently trashed child (different
   * timestamp) is not restored with its parent, and restoring the "wrong"
   * parent of a multi-parent child does not restore it either.
   */
  public function testMultiParentAndRestoreScoping(): void {
    // Independently trashed child: different timestamp, not restored with the
    // parent.
    $parent = $this->createTerm($this->vocabulary, ['name' => 'Parent']);
    $child = $this->createTerm($this->vocabulary, ['name' => 'Child', 'parent' => $parent->id()]);

    $child->delete();
    \Drupal::keyValue('trash_test')->set('time_offset', 100);
    $parent->delete();

    $this->restoreEntity('taxonomy_term', $parent->id());
    $this->assertNotEmpty(Term::load($parent->id()));
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $child->id())));

    // Multi-parent child: it stays live while one parent is live, is cascaded
    // with B's timestamp once both are gone, and only restoring B brings it
    // back (restoring A, the "wrong" timestamp, does not).
    \Drupal::keyValue('trash_test')->set('time_offset', 0);
    $parent_a = $this->createTerm($this->vocabulary, ['name' => 'Parent A']);
    $parent_b = $this->createTerm($this->vocabulary, ['name' => 'Parent B']);
    $child2 = $this->createTerm($this->vocabulary, ['name' => 'Child 2', 'parent' => [$parent_a->id(), $parent_b->id()]]);

    $parent_a->delete();
    $this->assertFalse(Trash::entityIsDeleted(Term::load($child2->id())));
    \Drupal::keyValue('trash_test')->set('time_offset', 200);
    $parent_b->delete();
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $child2->id())));

    $this->restoreEntity('taxonomy_term', $parent_a->id());
    $this->assertEmpty(Term::load($child2->id()));

    $this->restoreEntity('taxonomy_term', $parent_b->id());
    $this->assertNotEmpty(Term::load($child2->id()));
  }

  /**
   * Tests that trashed terms are excluded from queries and tree operations.
   *
   * Covers entity queries in active and ignore context, and direct database
   * queries tagged with 'taxonomy_term_access' (e.g. TermStorage::loadTree()).
   */
  public function testQueryFiltering(): void {
    $term_storage = $this->getEntityTypeManager()->getStorage('taxonomy_term');
    $active = $this->createTerm($this->vocabulary, ['name' => 'Active']);
    $trashed = $this->createTerm($this->vocabulary, ['name' => 'Trashed']);

    $trashed->delete();

    // Entity query in active context.
    $result = $term_storage->getQuery()
      ->condition('vid', $this->vocabulary->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertContains($active->id(), $result);
    $this->assertNotContains($trashed->id(), $result);

    // Entity query in ignore context.
    $result = $this->getTrashManager()->executeInTrashContext('ignore', fn () =>
      $term_storage->getQuery()
        ->condition('vid', $this->vocabulary->id())
        ->accessCheck(FALSE)
        ->execute()
    );
    $this->assertContains($trashed->id(), $result);

    // Taxonomy tree (direct database query with 'taxonomy_term_access' tag).
    $tree_tids = array_column($term_storage->loadTree($this->vocabulary->id()), 'tid');
    $this->assertContains($active->id(), $tree_tids);
    $this->assertNotContains($trashed->id(), $tree_tids);
  }

  /**
   * Tests purge for single terms, hierarchies, and multi-parent children.
   */
  public function testPurge(): void {
    // Single term: trash, restore, re-trash, purge.
    $term = $this->createTerm($this->vocabulary, ['name' => 'Term']);
    $term->delete();
    $this->assertTrue(Trash::entityIsDeleted($this->loadTrashedEntity('taxonomy_term', $term->id())));
    $this->restoreEntity('taxonomy_term', $term->id());
    $this->assertFalse(Trash::entityIsDeleted(Term::load($term->id())));
    Term::load($term->id())->delete();
    $this->purgeEntity('taxonomy_term', $term->id());
    $this->assertEmpty($this->loadTrashedEntity('taxonomy_term', $term->id()));

    // Parent with children: purge removes all.
    $parent = $this->createTerm($this->vocabulary, ['name' => 'Parent']);
    $child1 = $this->createTerm($this->vocabulary, ['name' => 'Child 1', 'parent' => $parent->id()]);
    $child2 = $this->createTerm($this->vocabulary, ['name' => 'Child 2', 'parent' => $parent->id()]);
    $parent->delete();
    $this->purgeEntity('taxonomy_term', $parent->id());
    $this->assertEmpty($this->loadTrashedEntity('taxonomy_term', $parent->id()));
    $this->assertEmpty($this->loadTrashedEntity('taxonomy_term', $child1->id()));
    $this->assertEmpty($this->loadTrashedEntity('taxonomy_term', $child2->id()));

    // Multi-parent child: purging one parent keeps the child.
    $p1 = $this->createTerm($this->vocabulary, ['name' => 'P1']);
    $p2 = $this->createTerm($this->vocabulary, ['name' => 'P2']);
    $shared = $this->createTerm($this->vocabulary, ['name' => 'Shared', 'parent' => [$p1->id(), $p2->id()]]);
    $p1->delete();
    $this->purgeEntity('taxonomy_term', $p1->id());
    $this->assertEmpty($this->loadTrashedEntity('taxonomy_term', $p1->id()));
    $this->assertNotEmpty(Term::load($shared->id()));
  }

  /**
   * Gets the parent term IDs from a term's parent field.
   */
  protected function getParentIds(Term $term): array {
    return array_column($term->get('parent')->getValue(), 'target_id');
  }

}
