<?php

declare(strict_types=1);

namespace Drupal\trash\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\trash\Hook\TrashEntityHooks;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listens to the Kernel View event to alter the translation overview.
 */
class TrashLanguageOverviewSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected TrashManagerInterface $trashManager,
    protected LanguageManagerInterface $languageManager,
    protected TrashEntityHooks $trashEntityHooks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[KernelEvents::VIEW] = ['onView', 1];
    return $events;
  }

  /**
   * Adds "deleted" status to the translation overview page.
   *
   * Neither of the original edit and delete operations will work on deleted
   * entities or translation, but they can be replaced by restore and purge
   * operations if the user has the appropriate permissions.
   * To load the correct revision(s) we need to deactivate the trash context
   * when loading them, else we may get an older non-deleted revision.
   * It may be tempting to set _trash_route on the translation overview route
   * to have the original controller load the correct revisions, but that would
   * completely deny translation access to anyone without the permission to view
   * deleted entities.
   *
   * Most of the logic from ContentTranslationController is done again here to
   * the correct revision and status for each language. It would have been
   * easier if content_translation had provided this data in the render array.
   *
   * @param \Symfony\Component\HttpKernel\Event\ViewEvent $event
   *   The Kernel View event.
   */
  public function onView(ViewEvent $event): void {
    if (is_array($event->getControllerResult()) && isset($event->getControllerResult()['content_translation_overview'])) {
      $build = $event->getControllerResult();
      // The entity set by the controller may be any revision and language,
      // depending on what was processed last in the list.
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $build['#entity'];
      $entity_type = $entity->getEntityType();
      if (!$entity instanceof ContentEntityInterface || !$this->trashManager->isEntityTypeEnabled($entity_type)) {
        return;
      }

      // Locate the columns by their header label rather than assuming the
      // operations column is last and the status column second-to-last. A
      // module adding a column would otherwise shift these and we'd write into
      // the wrong cells. Bail out if either column is missing.
      $header = $build['content_translation_overview']['#header'] ?? [];
      $status_column = $this->findColumn($header, $this->t('Status'));
      $operations_column = $this->findColumn($header, $this->t('Operations'));
      if ($status_column === NULL || $operations_column === NULL) {
        return;
      }

      // The "Deleted" status and the restore/purge operations depend on each
      // translation's deleted state and on the current user's permissions, so
      // vary the cached overview accordingly.
      $cacheability = CacheableMetadata::createFromRenderArray($build)
        ->addCacheContexts(['user.permissions']);

      $this->trashManager->executeInTrashContext('ignore', function () use (&$build, $entity, $status_column, $operations_column, $cacheability) {
        foreach (array_keys($this->languageManager->getLanguages()) as $row_index => $langcode) {
          $translation = $this->entityRepository->getActive($entity->getEntityTypeId(), $entity->id(), ['langcode' => $langcode]);
          if (!$translation instanceof ContentEntityInterface || $translation->language()->getId() !== $langcode) {
            continue;
          }
          $cacheability->addCacheableDependency($translation);
          $row = &$build['content_translation_overview']['#rows'][$row_index];

          if (!$translation->get('deleted')->isEmpty()) {
            $row[$status_column] = $this->t('Deleted');
            $operations = [];
            $this->trashEntityHooks->entityOperationAlter($operations, $translation);
            $row[$operations_column]['data']['#links'] = $operations;
          }
        }
      });

      $cacheability->applyTo($build);
      $event->setControllerResult($build);
    }
  }

  /**
   * Finds the index of a table column by its header label.
   *
   * @param array $header
   *   The table '#header' array. Cells are strings, stringable markup, or
   *   render arrays with a 'data' key.
   * @param string|\Stringable $label
   *   The header label to match.
   *
   * @return int|null
   *   The column index, or NULL if no header cell matches the label.
   */
  private function findColumn(array $header, string|\Stringable $label): ?int {
    foreach ($header as $index => $cell) {
      $cell_label = is_array($cell) ? ($cell['data'] ?? '') : $cell;
      if ((string) $cell_label === (string) $label) {
        return (int) $index;
      }
    }
    return NULL;
  }

}
