<?php

declare(strict_types=1);

namespace Drupal\trash\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\WorkspaceSafeFormInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\file\FileInterface;
use Drupal\trash\Exception\UnrestorableEntityException;
use Drupal\trash\Trash;
use Drupal\trash\TrashManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a generic base class for a content entity restore form.
 */
class EntityRestoreForm extends ContentEntityConfirmFormBase implements WorkspaceSafeFormInterface {

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
    return $this->t('Are you sure you want to restore the @entity-type %label?', [
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
  public function getDescription() {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();
    $untranslated = $entity->getUntranslated();
    if (!$entity->isDefaultTranslation() && Trash::entityIsDeleted($untranslated)) {
      return $this->t('Restoring the @language translation will also restore the @original_language original language.', [
        '@language' => $entity->language()->getName(),
        '@original_language' => $untranslated->language()->getName(),
      ]);
    }

    // @phpstan-ignore return.type
    return NULL;
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
    $language ??= $entity->language();

    $translation_languages = $entity->getTranslationLanguages(FALSE);
    if (count($translation_languages) > 0) {
      $translation_options = array_map(function (LanguageInterface $translation) use ($entity) {
        return $this->t('@translation_label: %entity_label', [
          '@translation_label' => $translation->getName(),
          '%entity_label' => $entity->getTranslation($translation->getId())->label(),
        ]);
      }, array_filter($translation_languages,
        function (LanguageInterface $translation) use ($entity, $language) {
          if ($translation->getId() === $language->getId()) {
            return FALSE;
          }
          // Only offer deleted translations the user can restore, applying
          // the same per-translation access check as the bulk restore form
          // and the restore action.
          $translation_entity = $entity->getTranslation($translation->getId());
          return Trash::entityIsDeleted($translation_entity) && $translation_entity->access('restore');
        }
      ));
      if (!empty($translation_options)) {
        $form['translations'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Translations'),
          '#description' => $this->t('Other translations to also restore.'),
          '#options' => $translation_options,
          '#default_value' => $entity->getTranslation($language->getId())->isDefaultTranslation() ? array_keys($translation_options) : [],
        ];
      }
    }

    $this->trashManager->getHandler($this->getEntity()->getEntityTypeId())?->restoreFormAlter($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);

    // Use the handler's validateRestore() to check for conflicts. This also
    // sets a flag so that preTrashRestore() skips duplicate validation.
    try {
      $this->trashManager->getHandler($entity->getEntityTypeId())?->validateRestore($entity);
    }
    catch (UnrestorableEntityException $e) {
      $form_state->setError($form, $e->getMessage());
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();
    // Not all entities support URL links.
    $supports_url = $entity->hasLinkTemplate('canonical') || $entity->hasLinkTemplate('edit-form');
    $restored_translation = FALSE;
    $args = [
      '@entity-type' => $entity->getEntityType()->getSingularLabel(),
      '@bundle' => $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId())[$entity->bundle()]['label'],
      '%label' => $entity->label() ?? $entity->id(),
    ];

    $langcodes = [$entity->language()->getId()];
    foreach (array_keys(array_filter($form_state->getValue('translations', []))) as $langcode) {
      // The translation may have been purged since the form was built.
      if (!$entity->hasTranslation($langcode)) {
        continue;
      }
      $translation = $entity->getTranslation($langcode);
      // Re-check access on submit: the checkbox options are access-filtered
      // when the form is built, but access may change before it is submitted.
      if (!$translation->access('restore')) {
        $this->messenger()->addWarning($this->t('The @language translation has not been restored because you do not have the necessary permissions.', [
          '@language' => $translation->language()->getName(),
        ]));
        continue;
      }
      $langcodes[] = $langcode;
    }
    // Restoring the original language ensures we don't end up in a weird
    // situation where a translated entity's source translation looks possible
    // to purge from the trashcan, but doing so unexpectedly also purges active
    // translations.
    if (!$entity->isDefaultTranslation()) {
      $untranslated = $entity->getUntranslated();
      if (Trash::entityIsDeleted($untranslated)) {
        $langcodes[] = $untranslated->language()->getId();
        $args += [
          '@language' => $entity->language()->getName(),
          '%untranslated_label' => $untranslated->label(),
          '@untranslated_language' => $untranslated->language()->getName(),
        ];
        $restored_translation = TRUE;
        if ($supports_url) {
          $args += [
            ':untranslated_url' => $untranslated->toUrl()->toString(),
          ];
        }
      }
    }

    try {
      Trash::restoreEntity($entity, $langcodes);
    }
    catch (UnrestorableEntityException $e) {
      $this->messenger()->addError($this->t('The @entity-type %label could not be restored from trash.', $args));
      if ($message = $e->getMessage()) {
        $this->messenger()->addError($message);
      }
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $form_state->setRedirectUrl($this->getRedirectUrl());

    if ($supports_url) {
      $args[':entity_url'] = $entity->toUrl()->toString();
    }
    elseif ($entity->getEntityTypeId() === 'file') {
      // Provide a link to the publicly restored file. Building the URL is
      // best-effort: a file on a stream wrapper that can not produce an
      // external URL throws, and the restore has already committed, so fall
      // back to the plain message instead of turning a successful restore
      // into an error.
      assert($entity instanceof FileInterface);
      $restored_file = $this->entityTypeManager->getStorage('file')->load($entity->id());
      if ($restored_file instanceof FileInterface) {
        try {
          $args[':entity_url'] = $restored_file->createFileUrl();
          $supports_url = TRUE;
        }
        catch (InvalidStreamWrapperException) {
          // Leave $supports_url FALSE so the plain message is used below.
        }
      }
    }
    if ($restored_translation) {
      if ($supports_url) {
        $message = $this->t('The @entity-type <a href=":entity_url">%label</a> (@language) and the <a href=":untranslated_url">%untranslated_label</a> (@untranslated_language) original language have been restored from trash.', $args);
      }
      else {
        $message = $this->t('The @entity-type %label (@language) and the %untranslated_label (@untranslated_language) original language have been restored from trash.', $args);
      }
    }
    else {
      if ($supports_url) {
        $message = $this->t('The @entity-type <a href=":entity_url">%label</a> has been restored from trash.', $args);
      }
      else {
        $message = $this->t('The @entity-type %label has been restored from trash.', $args);
      }
    }

    $this->messenger()->addStatus($message);
    // @todo Change log message if only a translation was restored?
    $this->getLogger('trash')->info('@entity-type (@bundle): restored %label.', $args);
  }

  /**
   * Returns the URL where the user should be redirected after restoring.
   *
   * @return \Drupal\Core\Url
   *   The redirect URL.
   */
  protected function getRedirectUrl() {
    $entity = $this->getEntity();
    if ($entity->hasLinkTemplate('canonical')) {
      // The entity is back, so it can be viewed outside the trash context.
      return $entity->toUrl();
    }

    return $this->getTrashListingUrl();
  }

}
