<?php

declare(strict_types=1);

namespace Drupal\trash\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\trash\Form\TrashSettingsForm;
use Drupal\trash\Handler\TrashHandlerInterface;
use Drupal\trash\TrashEntityPurger;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Various hook implementations for Trash.
 */
class TrashHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TrashManagerInterface $trashManager,
    protected LanguageManagerInterface $languageManager,
    protected TrashEntityPurger $trashEntityPurger,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected AccountInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected RouteBuilderInterface $routeBuilder,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id): void {
    $form_object = $form_state->getFormObject();
    $is_entity_delete_form = $form_object instanceof ContentEntityDeleteForm;
    $is_entity_multiple_delete_form = $form_object instanceof DeleteMultipleForm;
    $is_vbo_confirm_action_form = $form_id === 'views_bulk_operations_confirm_action';

    if (!($is_entity_delete_form || $is_entity_multiple_delete_form || $is_vbo_confirm_action_form)) {
      return;
    }

    $entity_type = $bundle = $entity = NULL;
    if ($is_entity_delete_form) {
      $entity = $form_object->getEntity();
      $entity_type = $entity->getEntityType();
      $bundle = $entity->bundle();
    }
    elseif ($is_entity_multiple_delete_form) {
      $entity_type_call = function () {
        // @phpstan-ignore-next-line
        return $this->entityType;
      };
      $entity_type = $entity_type_call->call($form_object);
    }
    elseif ($is_vbo_confirm_action_form) {
      $vbo_form_data = $form_state->getStorage()['views_bulk_operations'];
      if ($vbo_form_data['action_id'] !== 'views_bulk_operations_delete_entity') {
        return;
      }

      // Get the first item in the VBO form data to check its entity type. An
      // empty selection has no entity type to check, so there is nothing to
      // guard or relabel.
      $list = $vbo_form_data['list'] ?? [];
      $first_item = reset($list);
      if (!is_array($first_item) || !isset($first_item[2])) {
        return;
      }
      // @see \Drupal\views_bulk_operations\Form\ViewsBulkOperationsFormTrait::calculateEntityBulkFormKey()
      $entity_type = $this->entityTypeManager->getDefinition($first_item[2]);
    }

    if ($entity_type && !$this->trashManager->isEntityTypeEnabled($entity_type, $bundle)) {
      return;
    }

    // Only apply the context guard once we know this form is actually for a
    // trash-enabled entity type (and, for VBO, actually a delete action): an
    // unrelated delete form or bulk action must not 406 just because some
    // other part of the same request left the trash context non-active.
    if ($this->trashManager->getTrashContext() !== 'active') {
      throw new HttpException(Response::HTTP_NOT_ACCEPTABLE, 'Delete operations are not allowed outside a Trash context.');
    }

    // Change the message of the delete confirmation form to mention the actual
    // action that is about to happen.
    $params = [
      '@label' => !$is_entity_delete_form ? $entity_type->getPluralLabel() : $entity_type->getSingularLabel(),
      ':link' => Url::fromRoute('trash.admin_content_trash_entity_type', [
        'entity_type_id' => $entity_type->id(),
      ])->toString(),
    ];

    // Use different messages based on the user's access level.
    if ($this->currentUser->hasPermission('restore ' . $entity_type->id() . ' entities')) {
      $trash_settings = $this->configFactory->get('trash.settings');
      if ($trash_settings->get('auto_purge.enabled')) {
        $timestamp = strtotime(sprintf('+%s', $trash_settings->get('auto_purge.after')));
        $params['@time_period'] = $this->dateFormatter->formatDiff($this->time->getCurrentTime(), $timestamp);

        $entity_delete_label = $this->t('Deleting this @label will move it to the <a href=":link">trash</a>. You can restore it from the trash for a limited period of time (@time_period) if necessary.', $params);
        $entity_multiple_delete_label = $this->t('Deleting these @label will move them to the <a href=":link">trash</a>. You can restore them from the trash for a limited period of time (@time_period) if necessary.', $params);
      }
      else {
        $entity_delete_label = $this->t('Deleting this @label will move it to the <a href=":link">trash</a>. You can restore it from the trash at a later date if necessary.', $params);
        $entity_multiple_delete_label = $this->t('Deleting these @label will move them to the <a href=":link">trash</a>. You can restore them from the trash at a later date if necessary.', $params);
      }
    }
    elseif ($this->currentUser->hasPermission('access trash')) {
      $entity_delete_label = $this->t('Deleting this @label will move it to the <a href=":link">trash</a>.', $params);
      $entity_multiple_delete_label = $this->t('Deleting these @label will move them to the <a href=":link">trash</a>.', $params);
    }
    else {
      $entity_delete_label = $this->t('Deleting this @label will move it to the trash.', $params);
      $entity_multiple_delete_label = $this->t('Deleting these @label will move them to the trash.', $params);
    }

    $trash_handler = $this->trashManager->getHandler($entity_type->id());
    if (!$trash_handler instanceof TrashHandlerInterface) {
      // No handler is registered for this entity type (e.g. trash was just
      // enabled for it in the current request, before the container was
      // rebuilt). Nothing to alter the form with.
      return;
    }
    if (isset($form['description']['#markup']) && $form['description']['#markup'] instanceof TranslatableMarkup) {
      if (str_contains($form['description']['#markup']->getUntranslatedString(), 'This action cannot be undone.')) {
        if ($is_entity_delete_form) {
          $form['description']['#markup'] = $entity_delete_label;
          $trash_handler->deleteFormAlter($form, $form_state);
        }
        elseif ($is_entity_multiple_delete_form) {
          $form['description']['#markup'] = $entity_multiple_delete_label;
          $trash_handler->deleteFormAlter($form, $form_state, TRUE);
        }
      }
    }
    elseif ($is_vbo_confirm_action_form) {
      $form['description'] = [
        '#markup' => $entity_multiple_delete_label,
        '#weight' => -10,
      ];
      $trash_handler->deleteFormAlter($form, $form_state, TRUE);
    }
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->trashEntityPurger->cronPurge();
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(&$links): void {
    $links['navigation.trash'] = [
      'route_name' => 'trash.admin_content_trash',
      'title' => $this->t('Trash'),
      'menu_name' => 'content',
      'provider' => 'navigation',
      'weight' => 1000,
      'options' => [
        'icon' => [
          'pack_id' => 'trash',
          'icon_id' => 'trash',
          'settings' => [
            'class' => 'toolbar-button__icon',
            'size' => 25,
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled($modules, $is_syncing): void {
    if (!$is_syncing && (
        in_array('node', $modules, TRUE) ||
        (in_array('trash', $modules, TRUE) && $this->moduleHandler->moduleExists('node'))
      )
    ) {
      $trash_settings = $this->configFactory->getEditable('trash.settings');
      $enabled_entity_types = $trash_settings->get('enabled_entity_types') ?? [];
      $enabled_entity_types['node'] ??= [];

      if (in_array('path_alias', $modules, TRUE) ||
        (in_array('trash', $modules, TRUE) && $this->moduleHandler->moduleExists('path_alias'))
      ) {
        $enabled_entity_types['path_alias'] ??= [];
      }

      if ((in_array('menu_link_content', $modules, TRUE) ||
        (in_array('trash', $modules, TRUE) && $this->moduleHandler->moduleExists('menu_link_content')))
        && !in_array('menu_link_content', TrashSettingsForm::getUnsupportedEntityTypes(), TRUE)
      ) {
        $enabled_entity_types['menu_link_content'] ??= [];
      }

      $trash_settings->set('enabled_entity_types', $enabled_entity_types)->save();
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for 'configurable_language'.
   */
  #[Hook('configurable_language_insert')]
  public function configurableLanguageInsert(EntityInterface $entity): void {
    if (count($this->languageManager->getLanguages()) >= 2) {
      // Need to add translation link templates. Rebuilds are idempotent, so a
      // >= 2 guard is safe even when several languages are added at once.
      $this->entityTypeManager->clearCachedDefinitions();

      // Need to add the translation restore and purge routes.
      $this->routeBuilder->setRebuildNeeded();
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for 'configurable_language'.
   */
  #[Hook('configurable_language_delete')]
  public function configurableLanguageDelete(EntityInterface $entity): void {
    // The {language} slug in the restore/purge link templates and routes is
    // only registered on multilingual sites, so both caches have to be
    // invalidated together when a language is deleted. Invalidate them
    // unconditionally: ConfigurableLanguage::postDelete() may schedule a
    // container rebuild, so the injected language manager is not a reliable
    // source for a fresh language count here.
    $this->entityTypeManager->clearCachedDefinitions();
    $this->routeBuilder->setRebuildNeeded();
  }

}
