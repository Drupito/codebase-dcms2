<?php

declare(strict_types=1);

namespace Drupal\trash\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\WorkspaceSafeFormInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a generic base class for a content entity purge form.
 */
class EntityPurgeForm extends ContentEntityConfirmFormBase implements WorkspaceSafeFormInterface {

  use TrashOperationRedirectTrait;

  /**
   * The trash manager.
   */
  protected TrashManagerInterface $trashManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->trashManager = $container->get('trash.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();
    if (!$entity->isDefaultTranslation()) {
      return $this->t('Are you sure you want to permanently delete the @language translation of the @entity-type %label?', [
        '@language' => $entity->language()->getName(),
        '@entity-type' => $this->getEntity()->getEntityType()->getSingularLabel(),
        '%label' => $this->getEntity()->label(),
      ]);
    }
    if (count($entity->getTranslationLanguages()) > 1) {
      return $this->t('Are you sure you want to permanently delete the @entity-type %label and all translations?', [
        '@entity-type' => $this->getEntity()->getEntityType()->getSingularLabel(),
        '%label' => $this->getEntity()->label() ?? $this->getEntity()->id(),
      ]);
    }
    return $this->t('Are you sure you want to permanently delete the @entity-type %label?', [
      '@entity-type' => $this->getEntity()->getEntityType()->getSingularLabel(),
      '%label' => $this->getEntity()->label() ?? $this->getEntity()->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // Cancelling leaves the entity in the trash, so it can still be viewed.
    return $this->getTrashedEntityUrl() ?? $this->getTrashListingUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LanguageInterface $language = NULL) {
    // Our entity converter already determined the proper entity translation to
    // use, but \Drupal\Core\Entity\ContentEntityForm::initFormLangcodes()
    // doesn't use that information, so we have to set the langcode manually.
    if ($language) {
      $form_state->set('langcode', $language->getId());
    }
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();

    if ($entity->isDefaultTranslation()) {
      if (count($entity->getTranslationLanguages()) > 1) {
        $languages = [];
        foreach ($entity->getTranslationLanguages() as $translation_language) {
          $languages[] = $translation_language->getName();
        }

        $form['deleted_translations'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('The following @entity-type translations will be permanently deleted:', [
            '@entity-type' => $entity->getEntityType()->getSingularLabel(),
          ]),
          '#items' => $languages,
        ];

        $form['actions']['submit']['#value'] = $this->t('Delete all translations');
      }
    }
    else {
      $form['actions']['submit']['#value'] = $this->t('Delete @language translation', ['@language' => $entity->language()->getName()]);
    }

    $this->trashManager->getHandler($this->getEntity()->getEntityTypeId())?->purgeFormAlter($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();

    if (!$entity->isDefaultTranslation()) {
      $message = $this->t('The @entity-type %label @language translation has been permanently deleted.', [
        '@entity-type' => $entity->getEntityType()->getSingularLabel(),
        '%label' => $entity->label() ?? $entity->id(),
        '@language' => $entity->language()->getName(),
      ]);
      $untranslated_entity = $entity->getUntranslated();
      $untranslated_entity->removeTranslation($entity->language()->getId());
      $untranslated_entity->save();
    }
    else {
      $message = $this->t('The @entity-type %label has been permanently deleted.', [
        '@entity-type' => $entity->getEntityType()->getSingularLabel(),
        '%label' => $entity->label() ?? $entity->id(),
      ]);

      $entity->delete();
    }

    $form_state->setRedirectUrl($this->getRedirectUrl());

    $this->messenger()->addStatus($message);

    // @todo Change log message if only a translation was purged?
    $this->getLogger('trash')->info('@entity-type (@bundle): permanently deleted %label.', [
      '@entity-type' => $entity->getEntityType()->getLabel(),
      '@bundle' => $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'],
      '%label' => $entity->label() ?? $entity->id(),
    ]);
  }

  /**
   * Returns the URL where the user should be redirected after purging.
   *
   * @return \Drupal\Core\Url
   *   The redirect URL.
   */
  protected function getRedirectUrl() {
    // The entity is gone by now, so its own page is not an option.
    return $this->getTrashListingUrl();
  }

}
