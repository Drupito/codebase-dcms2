<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Controller;

use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas_translate\ExtensionUrl;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adds edit/add translation links to the Canvas editor's language switcher.
 *
 * UPSTREAM(canvas): Canvas only emits `delete-form` translation links. Remove
 * this decorator once ApiLayoutController::get() emits `edit-form`/`create`
 * from the content_translation routes, which this module can then redirect.
 */
final readonly class ApiLayoutControllerDecorator {

  public function __construct(
    private ApiLayoutController $inner,
    private LanguageManagerInterface $languageManager,
    private AccountInterface $currentUser,
  ) {}

  /**
   * Adds translation links for a canvas_page to the inner layout.
   */
  public function get(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    $envelope = $this->inner->get($request, $entity, $preview_entity);
    if (!$entity instanceof Page || !$this->currentUser->hasPermission('translate canvas content')) {
      return $envelope;
    }

    $data = $envelope->additionalData;
    $source_langcode = $entity->getUntranslated()->language()->getId();
    foreach (\array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($langcode === $source_langcode) {
        continue;
      }
      $rel = $entity->hasTranslation($langcode) ? CanvasUriDefinitions::LINK_REL_EDIT : CanvasUriDefinitions::LINK_REL_CREATE;
      $data['translations']['links'][$langcode][$rel] = ExtensionUrl::forPage((int) $entity->id(), $langcode)->toString();
    }

    return new PreviewEnvelope($envelope->previewRenderArray, $data, $envelope->statusCode);
  }

  /**
   * Passes through to the inner controller.
   */
  public function patch(Request $request, FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    return $this->inner->patch($request, $entity, $preview_entity);
  }

  /**
   * Passes through to the inner controller.
   */
  public function post(Request $request, FieldableEntityInterface|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): PreviewEnvelope {
    return $this->inner->post($request, $entity, $preview_entity);
  }

  /**
   * Passes through to the inner controller.
   */
  public function getLabel(Request $request, (ContentEntityInterface&EntityPublishedInterface)|ComponentTreeConfigEntityBase $entity, ?ContentEntityInterface $preview_entity = NULL): string {
    return $this->inner->getLabel($request, $entity, $preview_entity);
  }

  /**
   * Passes through to the inner controller.
   */
  public function draftContentTemplate(Request $request, string $entity_type, ContentEntityInterface $preview_entity): JsonResponse {
    return $this->inner->draftContentTemplate($request, $entity_type, $preview_entity);
  }

}
