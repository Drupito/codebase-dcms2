<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_translate\TestSite;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Service\TranslationDraftStore;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\File\FileExists;
use Drupal\Core\Language\LanguageInterface;
use Drupal\editor\Entity\Editor;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\TestSite\TestSetupInterface;

// @phpstan-ignore function.impossibleType
if (!\class_exists(TestSetupInterface::class)) {
  // test-discovery may run before the \Drupal\TestSite namespace is
  // autoloadable (run-tests.sh sets the cwd to the Drupal root). Mirror
  // CanvasTestSetup.
  $root = \getcwd();
  foreach (['/core', '/web/core', ''] as $prefix) {
    $interface = $root . $prefix . '/tests/Drupal/TestSite/TestSetupInterface.php';
    if (\file_exists($interface)) {
      require_once $interface;
      break;
    }
  }
}

// cspell:ignore brouillon publié publiée périmée

/**
 * Provisions an isolated Drupal install for the Canvas Translate e2e tests.
 *
 * Run by `core/scripts/test-site.php install --setup-file …`: installs the
 * module + its translation dependencies, adds a target language with
 * canvas_page translation enabled, and seeds two pages — a plain one (title /
 * description) and one carrying a code component with a rich-text prop. The
 * rich-text fixture is a JavaScript (code) component on purpose: SDC props
 * backed by a multi-property field trip a dev-mode typed-config assertion in
 * canvas core's inputs extractor (the www runs with zend.assertions=1), whereas
 * code components take a path that does not.
 *
 * The specs discover the seeded pages by their (stable) labels via the
 * dashboard API, so nothing has to be passed back out of the sandbox here.
 *
 * @see \Drupal\Tests\canvas\TestSite\CanvasTestSetup
 * @see web/modules/contrib/canvas_translate/tests/manual-setup.php
 */
class CanvasTranslateTestSetup implements TestSetupInterface {

  /**
   * The target translation language seeded into the sandbox.
   */
  public const string LANGCODE = 'fr';

  /**
   * Stable labels the specs look the seeded pages up by.
   */
  public const string RICH_PAGE_TITLE = 'E2E Rich Text Page';
  public const string FORMAT_RICH_PAGE_TITLE = 'E2E Format Rich Text Page';
  public const string INLINE_RICH_PAGE_TITLE = 'E2E Inline Rich Text Page';
  public const string PLAIN_PAGE_TITLE = 'E2E Translate Page';
  public const string PLAIN_PAGE_DESCRIPTION = 'E2E source description for the translate test.';
  public const string OUTDATED_PAGE_TITLE = 'E2E Outdated Page';
  public const string REVIEW_PAGE_TITLE = 'E2E Review Page';

  /**
   * Published French title vs the pending draft title on the review page.
   *
   * So the review screen has a published→draft change to diff and approve.
   */
  public const string REVIEW_PUBLISHED_TITLE = 'Revue E2E (publié)';
  public const string REVIEW_DRAFT_TITLE = 'Revue E2E (brouillon)';

  /**
   * The rich-text source HTML the rich page is seeded with.
   */
  public const string RICH_SOURCE_HTML = '<p>Hello <strong>world</strong></p>';

  /**
   * UUID of the media entity the rich-text fixtures embed.
   */
  public const string MEDIA_UUID = '7a1c2e3f-4b5d-4e6f-8a9b-0c1d2e3f4a5b';

  /**
   * Rich-text source HTML the rich prop's format allows.
   *
   * A heading, a managed link, a phrase in another language and an embedded
   * media item, serialized as core's CKEditor 5 writes them (`&nbsp;` inside
   * the embed): the format's own editor keeps all of them.
   *
   * @see self::extendRichTextFormat()
   */
  public const string FORMAT_RICH_SOURCE_HTML = '<h2>Heading</h2><p><a href="/node/1" data-entity-type="node" data-entity-uuid="5f3b9a3e-0c2d-4f6a-9b1e-2d7c8e9f0a1b">Link</a> to <em lang="de">Welt</em></p><drupal-media data-entity-type="media" data-entity-uuid="' . self::MEDIA_UUID . '">&nbsp;</drupal-media>';

  /**
   * {@inheritdoc}
   */
  public function setup(): void {
    \Drupal::service('module_installer')->install([
      'language',
      'content_translation',
      'canvas',
      'canvas_translate',
    ]);

    $this->addLanguage();
    $this->enableCanvasPageTranslation();
    $this->createMedia();
    $this->extendRichTextFormat();
    $this->createRichPage();
    $this->createPlainPage();
    $this->createOutdatedPage();
    $this->createReviewPage();
  }

  /**
   * Adds the target language.
   */
  private function addLanguage(): void {
    if (!ConfigurableLanguage::load(self::LANGCODE)) {
      ConfigurableLanguage::createFromLangcode(self::LANGCODE)->save();
    }
  }

  /**
   * Enables canvas_page translation, including base-field translatability.
   *
   * Mirrors tests/manual-setup.php: Canvas ships canvas_page base fields with
   * translatable=FALSE overrides, so without flipping them a translation would
   * clobber the source.
   */
  private function enableCanvasPageTranslation(): void {
    ContentLanguageSettings::loadByEntityTypeBundle('canvas_page', 'canvas_page')
      ->setDefaultLangcode(LanguageInterface::LANGCODE_SITE_DEFAULT)
      ->setLanguageAlterable(TRUE)
      ->save();
    \Drupal::service('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);

    $base = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('canvas_page');
    foreach (['title', 'description', 'components', 'image', 'path'] as $field_name) {
      if (!isset($base[$field_name])) {
        continue;
      }
      $override = BaseFieldOverride::loadByName('canvas_page', 'canvas_page', $field_name)
        ?? BaseFieldOverride::createFromBaseFieldDefinition($base[$field_name], 'canvas_page');
      $override->set('translatable', TRUE)->save();
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Creates an image media item for the rich-text fixtures to embed.
   */
  private function createMedia(): void {
    $type = MediaType::load('image');
    if ($type === NULL) {
      $type = MediaType::create(['id' => 'image', 'label' => 'Image', 'source' => 'image']);
      $type->save();
      $source_field = $type->getSource()->createSourceField($type);
      $source_field->getFieldStorageDefinition()->save();
      $source_field->save();
      $type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
    }
    $source_field_name = $type->getSource()->getSourceFieldDefinition($type)?->getName();
    \assert(\is_string($source_field_name));

    $file_system = \Drupal::service('file_system');
    $uri = $file_system->copy(\Drupal::root() . '/core/misc/druplicon.png', 'public://druplicon.png', FileExists::Replace);
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    Media::create([
      'bundle' => 'image',
      'uuid' => self::MEDIA_UUID,
      'name' => 'Druplicon',
      $source_field_name => ['target_id' => $file->id(), 'alt' => 'Druplicon'],
    ])->save();
  }

  /**
   * Lets the rich prop's format use headings, managed links and media.
   *
   * Canvas's block format only allows basic inline markup. Sites extend it
   * (headings, media, entity links); the translation editor must then offer
   * exactly the same. Enabling `entity_links` turns on core's entity link
   * plugin, which writes `data-entity-*` attributes, and `media_embed` turns
   * on core's media plugin, as on a real site.
   */
  private function extendRichTextFormat(): void {
    $format = FilterFormat::load('canvas_html_block');
    \assert($format instanceof FilterFormat);
    $format->setFilterConfig('filter_html', [
      'status' => TRUE,
      'settings' => [
        'allowed_html' => '<h2> <strong> <em lang> <u> <a href data-entity-type data-entity-uuid data-entity-metadata> <p> <br> <ul> <ol> <li> <drupal-media data-entity-type data-entity-uuid alt>',
        'filter_html_help' => FALSE,
        'filter_html_nofollow' => FALSE,
      ],
    ]);
    $format->setFilterConfig('entity_links', ['status' => TRUE]);
    $format->setFilterConfig('media_embed', [
      'status' => TRUE,
      // Embeds are rendered after the HTML filter, as core requires.
      'weight' => 100,
      'settings' => [
        'default_view_mode' => 'default',
        'allowed_view_modes' => [],
        'allowed_media_types' => [],
      ],
    ]);
    $format->save();

    $editor = Editor::load('canvas_html_block');
    \assert($editor instanceof Editor);
    $settings = $editor->getSettings();
    \array_unshift($settings['toolbar']['items'], 'heading', '|');
    $settings['toolbar']['items'][] = 'drupalMedia';
    $settings['plugins']['ckeditor5_heading'] = ['enabled_headings' => ['heading2']];
    $settings['plugins']['media_media'] = ['allow_view_mode_override' => FALSE];
    $editor->setSettings($settings)->save();

    $violations = $editor->getTypedData()->validate();
    if ($violations->count() > 0) {
      throw new \LogicException('Invalid canvas_html_block editor: ' . (string) $violations);
    }
  }

  /**
   * Creates pages holding a code component with a rich-text prop.
   */
  private function createRichPage(): void {
    // A code (JavaScript) component: a rich-text `text` prop (text_long, format
    // canvas_html_block) plus a non-translatable `variant` enum.
    JavaScriptComponent::create([
      'machineName' => 'translate_e2e_paragraph',
      'name' => 'Translate E2E Paragraph',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Text',
          'contentMediaType' => 'text/html',
          'x-formatting-context' => 'block',
          'examples' => ['<p>Example</p>'],
        ],
        'variant' => [
          'type' => 'string',
          'title' => 'Variant',
          'enum' => ['body', 'eyebrow'],
          'meta:enum' => ['body' => 'Body', 'eyebrow' => 'Eyebrow'],
          'examples' => ['body'],
        ],
      ],
      'js' => ['original' => 'export default () => null;', 'compiled' => 'export default () => null;'],
      'css' => ['original' => '', 'compiled' => ''],
      'slots' => [],
      'dataDependencies' => [],
    ])->save();
    $component = Component::load('js.translate_e2e_paragraph');
    \assert($component instanceof Component);

    Page::create([
      'title' => self::RICH_PAGE_TITLE,
      'langcode' => 'en',
      'status' => 1,
      'components' => [
        [
          'uuid' => 'a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6',
          'component_id' => 'js.translate_e2e_paragraph',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'text' => ['value' => self::RICH_SOURCE_HTML, 'format' => 'canvas_html_block'],
            'variant' => 'body',
          ],
          'label' => 'Paragraph',
        ],
      ],
    ])->save();

    Page::create([
      'title' => self::FORMAT_RICH_PAGE_TITLE,
      'langcode' => 'en',
      'status' => 1,
      'components' => [
        [
          'uuid' => 'c1c2c3c4-d1d2-4e3e-8f4f-a1a2a3a4a5a6',
          'component_id' => 'js.translate_e2e_paragraph',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'text' => ['value' => self::FORMAT_RICH_SOURCE_HTML, 'format' => 'canvas_html_block'],
            'variant' => 'body',
          ],
          'label' => 'Paragraph',
        ],
      ],
    ])->save();

    // A second text format, so a page using it loads its editor after the
    // first format's libraries are already on the page.
    Page::create([
      'title' => self::INLINE_RICH_PAGE_TITLE,
      'langcode' => 'en',
      'status' => 1,
      'components' => [
        [
          'uuid' => 'a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6',
          'component_id' => 'js.translate_e2e_paragraph',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'text' => ['value' => 'Hello <strong>inline</strong>', 'format' => 'canvas_html_inline'],
            'variant' => 'body',
          ],
          'label' => 'Paragraph',
        ],
      ],
    ])->save();
  }

  /**
   * Creates a plain page (title + description, no components).
   */
  private function createPlainPage(): void {
    Page::create([
      'title' => self::PLAIN_PAGE_TITLE,
      'description' => self::PLAIN_PAGE_DESCRIPTION,
      'langcode' => 'en',
      'status' => 1,
    ])->save();
  }

  /**
   * Creates a page whose published French translation is outdated.
   *
   * Publishes an in-sync French translation (the baseline), then edits the
   * source description in a new revision so OutdatedFlagHooks flags the
   * translation outdated with one changed field — the fixture for the
   * guided-review specs. The title is left unchanged so specs can still look
   * the page up by label.
   */
  private function createOutdatedPage(): void {
    $page = Page::create([
      'title' => self::OUTDATED_PAGE_TITLE,
      'description' => 'E2E outdated source description.',
      'langcode' => 'en',
      'status' => 1,
    ]);
    $page->save();

    // Publish a French translation — the in-sync baseline (outdated = FALSE).
    $translation = $page->addTranslation(self::LANGCODE, $page->toArray());
    $translation->set('title', 'Page périmée E2E');
    $translation->set('description', 'Description périmée FR.');
    if ($translation->hasField('content_translation_outdated')) {
      $translation->set('content_translation_outdated', FALSE);
    }
    if ($translation->hasField('content_translation_source')) {
      $translation->set('content_translation_source', 'en');
    }
    $page->save();

    // Edit the source description in a new revision → the French translation is
    // flagged outdated, with the description as the single changed field.
    $reloaded = Page::load($page->id());
    \assert($reloaded instanceof Page);
    $reloaded->set('description', 'E2E outdated source description. (changed)');
    $reloaded->setNewRevision(TRUE);
    $reloaded->save();
  }

  /**
   * Creates a page with a published French translation and a pending draft.
   *
   * The draft changes the title, so the review screen has a published→draft
   * change to diff (and approve), and the page appears in the review queue. The
   * fixture for the review/approve specs.
   */
  private function createReviewPage(): void {
    $page = Page::create([
      'title' => self::REVIEW_PAGE_TITLE,
      'description' => 'E2E review source description.',
      'langcode' => 'en',
      'status' => 1,
    ]);
    $page->save();

    // Publish an in-sync French baseline (so the draft has something to diff
    // against).
    $translation = $page->addTranslation(self::LANGCODE, $page->toArray());
    $translation->set('title', self::REVIEW_PUBLISHED_TITLE);
    $translation->set('description', 'Description publiée FR.');
    if ($translation->hasField('content_translation_outdated')) {
      $translation->set('content_translation_outdated', FALSE);
    }
    if ($translation->hasField('content_translation_source')) {
      $translation->set('content_translation_source', 'en');
    }
    $page->save();

    // Stage a pending draft that changes only the title.
    $key = TranslationDraftStore::key(
      TranslationDraftStore::contentSubject('canvas_page', (int) $page->id()),
      self::LANGCODE,
    );
    \Drupal::service(TranslationDraftStore::class)->set($key, ['title' => self::REVIEW_DRAFT_TITLE]);
  }

}
