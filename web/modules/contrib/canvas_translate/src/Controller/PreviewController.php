<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Service\TranslatableFieldGlue;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a full-page preview of a translation, with the draft applied.
 *
 * Returns a normally-themed render array (not JSON) so Drupal attaches the
 * component CSS/JS assets — the editor's preview pane points an iframe here.
 * Renders the in-progress draft when one exists, otherwise the stored
 * translation, so translators see their edits in context before publishing.
 *
 * The draft is read from this module's own store and applied onto the current
 * stored translation in memory; nothing is persisted.
 */
final class PreviewController implements ContainerInjectionInterface {

  /**
   * Plain translatable string fields handled outside the component tree.
   */
  private const array SIMPLE_FIELDS = ['title', 'description'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TranslationDraftStore $draftStore,
    private readonly TranslatableFieldGlue $glue,
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get(TranslationDraftStore::class),
      $container->get(TranslatableFieldGlue::class),
      $container->get('bare_html_page_renderer'),
    );
  }

  /**
   * Renders the page in a language with the pending draft applied.
   */
  public function preview(ContentEntityInterface $canvas_page, string $langcode): Response {
    if (!\array_key_exists($langcode, $this->languageManager->getLanguages())) {
      throw new NotFoundHttpException();
    }
    if (!$canvas_page->access('update')) {
      throw new AccessDeniedHttpException();
    }
    $storage = $this->entityTypeManager->getStorage(Page::ENTITY_TYPE_ID);
    $stored = $storage->loadUnchanged($canvas_page->id());
    \assert($stored instanceof Page);

    $target = $stored->hasTranslation($langcode)
      ? $stored->getTranslation($langcode)
      : $stored->addTranslation($langcode, $stored->toArray());

    // Apply the in-progress draft (if any) onto the target in memory so
    // unpublished edits show in the preview. Only non-empty translatable text
    // is written, onto the current structure — exactly as ::publish() does —
    // and the entity is never saved.
    $values = $this->draftStore->get(
      TranslationDraftStore::key(TranslationDraftStore::contentSubject(Page::ENTITY_TYPE_ID, $stored->id()), $langcode),
    );
    if ($values !== NULL) {
      foreach (self::SIMPLE_FIELDS as $field_name) {
        if (($values[$field_name] ?? '') !== '' && $target->hasField($field_name)) {
          $target->set($field_name, $values[$field_name]);
        }
      }
      $this->glue->applyValuesToComponents($values, $target->get('components'));
    }

    $build = $this->entityTypeManager->getViewBuilder(Page::ENTITY_TYPE_ID)->view($target, 'full', $langcode);
    // The preview reflects unsaved draft state — never cache it.
    $build['#cache']['max-age'] = 0;

    // Render a *bare* page: content + its attached component assets, but no
    // admin toolbar, blocks, or other site chrome.
    $response = $this->bareHtmlPageRenderer->renderBarePage($build, '', 'page');
    $response->setMaxAge(0);
    return $response;
  }

}
