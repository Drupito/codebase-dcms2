<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Hook;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears translation drafts when a translatable entity is deleted.
 *
 * Translation drafts live in this module's own store, keyed by the entity, so
 * we own their lifecycle: when a page or a config layout (content template /
 * page region) is deleted, every language-scoped draft for it is removed —
 * including draft-only languages that were never published — so no orphaned
 * draft remains.
 */
final class DraftCleanupHooks implements EventSubscriberInterface {

  public function __construct(
    private readonly TranslationDraftStore $draftStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT => 'onConfigImport'];
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    // Drafts are not config, so a config import must not destroy them. An
    // import recreates a config entity whose UUID differs between sites (for
    // example the page variant Canvas creates on each site during its update),
    // and the recreated entity picks the drafts up again by name. What the
    // import really deletes is cleaned up once it is complete.
    // @see ::onConfigImport()
    if ($entity instanceof ConfigEntityInterface && $entity->isSyncing()) {
      return;
    }
    $prefix = match (TRUE) {
      $entity instanceof Page => TranslationDraftStore::contentSubject(Page::ENTITY_TYPE_ID, $entity->id()) . ':',
      $entity instanceof ComponentTreeConfigEntityBase => $entity->getConfigDependencyName() . ':',
      default => NULL,
    };
    if ($prefix !== NULL) {
      $this->deleteDrafts([$prefix]);
    }
  }

  /**
   * Clears the drafts of config a config import deleted and did not recreate.
   */
  public function onConfigImport(ConfigImporterEvent $event): void {
    $deleted = \array_diff($event->getChangelist('delete'), $event->getChangelist('create'));
    if ($deleted !== []) {
      $this->deleteDrafts(\array_map(static fn (string $name): string => $name . ':', $deleted));
    }
  }

  /**
   * Deletes every draft whose key starts with one of the prefixes.
   *
   * @param list<string> $prefixes
   *   Draft key prefixes, each ending in ":".
   */
  private function deleteDrafts(array $prefixes): void {
    foreach (\array_keys($this->draftStore->allKeys()) as $key) {
      foreach ($prefixes as $prefix) {
        if (\str_starts_with($key, $prefix)) {
          $this->draftStore->delete($key);
          break;
        }
      }
    }
  }

}
