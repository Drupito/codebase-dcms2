<?php

declare(strict_types=1);

namespace Drupal\trash_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\trash\TrashManagerInterface;
use Drupal\views\Plugin\views\cache\CachePluginBase;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Views hooks for the Trash Test module.
 */
class TrashTestViewsHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly TrashManagerInterface $trashManager,
    #[Autowire(service: 'keyvalue')]
    protected readonly KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * Implements hook_views_data_alter().
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    // Add a reverse relationship: show all entities that reference this entity.
    $data['trash_test_field_data']['referenced_by'] = [
      'title' => $this->t('Referenced by TrashTestEntity'),
      'help' => $this->t('Entities that reference this entity via the reference field.'),
      'relationship' => [
        'id' => 'standard',
        'label' => $this->t('Referenced by TrashTestEntity'),
        'base' => 'trash_test_field_data',
        'base field' => 'reference',
        'relationship field' => 'id',
        'entity_type' => 'trash_test_entity',
      ],
    ];
  }

  /**
   * Implements hook_views_pre_execute().
   */
  #[Hook('views_pre_execute')]
  public function viewsPreExecute(ViewExecutable $view): void {
    if (in_array($view->id(), ['trash_test_view', 'trash_test_view_relationship'], TRUE)) {
      $this->keyValue->get('trash_test')->set('views_pre_execute.trash_context', $this->trashManager->getTrashContext());
    }
  }

  /**
   * Implements hook_views_post_execute().
   */
  #[Hook('views_post_execute')]
  public function viewsPostExecute(ViewExecutable $view): void {
    if (in_array($view->id(), ['trash_test_view', 'trash_test_view_relationship'], TRUE)) {
      $this->keyValue->get('trash_test')->set('views_post_execute.trash_context', $this->trashManager->getTrashContext());
    }
  }

  /**
   * Implements hook_views_pre_render().
   */
  #[Hook('views_pre_render')]
  public function viewsPreRender(ViewExecutable $view): void {
    if (in_array($view->id(), ['trash_test_view', 'trash_test_view_relationship'], TRUE)) {
      $this->keyValue->get('trash_test')->set('views_pre_render.trash_context', $this->trashManager->getTrashContext());
    }
  }

  /**
   * Implements hook_views_post_render().
   */
  #[Hook('views_post_render')]
  public function viewsPostRender(ViewExecutable $view, array &$output, CachePluginBase $cache): void {
    if (in_array($view->id(), ['trash_test_view', 'trash_test_view_relationship'], TRUE)) {
      $this->keyValue->get('trash_test')->set('views_post_render.trash_context', $this->trashManager->getTrashContext());
    }
  }

}
