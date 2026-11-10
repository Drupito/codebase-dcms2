<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_translate\Controller\ApiTranslateController;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Status-report check: canvas_page translation must be enabled.
 *
 * Canvas ships canvas_page base fields as non-translatable, and a config
 * import can silently revert the language settings. When page translation
 * cannot work at all (translation not enabled for the bundle, or no written
 * field translatable — see ApiTranslateController::translatabilitySetup()),
 * the API refuses and the SPA hides the workspace, so surface it here too.
 * Individually non-translatable fields are normal site configuration and are
 * not reported.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $setup = ApiTranslateController::translatabilitySetup(
      $this->entityFieldManager->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID),
      $this->entityTypeBundleInfo->getBundleInfo(Page::ENTITY_TYPE_ID),
    );
    if (!$setup['blocked']) {
      return [];
    }
    return [
      'canvas_translate_translatability' => [
        'title' => $this->t('Canvas Translate'),
        'value' => $this->t('Translation is not enabled for Canvas pages.'),
        'description' => $this->t('Translating a page would overwrite the original language, so Canvas Translate hides the page-translation workspace. Enable translation for Canvas pages and select the fields you want to be translated on the <a href=":url">Content language settings</a> page.', [':url' => Url::fromRoute('language.content_settings_page')->toString()]),
        // An error once the site is actually multilingual; before that the
        // module is installed but setup is simply unfinished.
        'severity' => $this->languageManager->isMultilingual() ? RequirementSeverity::Error : RequirementSeverity::Warning,
      ],
    ];
  }

}
