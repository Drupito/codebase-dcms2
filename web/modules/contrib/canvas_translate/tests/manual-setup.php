<?php

/**
 * @file
 * Dev smoke-setup: add French, enable canvas_page translation, create a page.
 *
 * Run with:
 * @code
 * ddev drush scr web/modules/contrib/canvas_translate/tests/manual-setup.php
 * @endcode
 */

use Drupal\canvas\Entity\Page;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;

if (!ConfigurableLanguage::load('fr')) {
  ConfigurableLanguage::createFromLangcode('fr')->save();
  echo "Added French.\n";
}

// Enabling content translation for the bundle requires the
// ContentLanguageSettings to be saved too — without it the translatable base
// fields (title, components, description) stay effectively non-translatable and
// writing a translation clobbers the source. This mirrors Canvas's own
// TranslationTest setup.
ContentLanguageSettings::loadByEntityTypeBundle('canvas_page', 'canvas_page')
  ->setDefaultLangcode(LanguageInterface::LANGCODE_SITE_DEFAULT)
  ->setLanguageAlterable(TRUE)
  ->save();
\Drupal::service('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);

// Canvas ships canvas_page base fields with translatable=FALSE overrides; flip
// the source-bearing fields to translatable so translations don't clobber the
// source. (In production this is done via the Content language admin form.)
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
\Drupal::service('router.builder')->setRebuildNeeded();
echo "Enabled content translation for canvas_page (base fields translatable).\n";

$existing = \Drupal::entityTypeManager()->getStorage('canvas_page')
  ->loadByProperties(['title' => 'Translate smoke test']);
if (!$existing) {
  $page = Page::create([
    'title' => 'Translate smoke test',
    'description' => 'A page used to smoke-test Canvas Translate.',
    'langcode' => 'en',
    'status' => 1,
  ]);
  $page->save();
  echo "Created canvas_page #{$page->id()}.\n";
}
else {
  $page = reset($existing);
  echo "Reusing canvas_page #{$page->id()}.\n";
}
