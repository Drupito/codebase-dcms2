<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The published source strings a config translation was last reviewed against.
 *
 * Config "translations" are language config overrides — they have no
 * content_translation_outdated field and no revisions, so there is nothing to
 * diff a stale override against. This entity snapshots the source strings at
 * each publish, keyed exactly like the editor rows, so the module can tell BOTH
 * whether an override is stale AND which fields changed (the same "Source was…"
 * experience content has).
 *
 * It is a CONFIG entity, not a keyvalue record, on purpose: the snapshot then
 * exports with the site and survives deploy. The previous keyvalue store did
 * not, so after a deploy every override falsely read up-to-date on the very
 * environment editors use. The snapshot also CANNOT live inside the
 * LanguageConfigOverride itself — Canvas validates every override against the
 * config schema and rejects unknown bookkeeping keys.
 *
 * ID is "{langcode}.{config_name}", e.g.
 * "fr.canvas.content_template.node.article.full". A config dependency on
 * {config_name} makes the baseline disappear automatically when the translated
 * ContentTemplate / PageRegion is deleted.
 *
 * @see \Drupal\canvas_translate\Service\ConfigTranslatableGlue
 * @see \Drupal\canvas\Entity\StagedLanguageConfigOverride
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Config translation baseline'),
  config_prefix: 'baseline',
  entity_keys: [
    'id' => 'id',
  ],
  config_export: [
    'id',
    'langcode',
    'config_name',
    'source',
  ],
)]
final class ConfigBaseline extends ConfigEntityBase {

  public const string ENTITY_TYPE_ID = 'canvas_translate_baseline';

  /**
   * ID, composed as "{langcode}.{config_name}".
   */
  protected string $id;

  /**
   * The translated config's name.
   *
   * For example canvas.content_template.node.article.full.
   *
   * The target language code is stored in ConfigEntityBase::$langcode.
   */
  protected string $config_name = '';

  /**
   * The source strings as of the last publish, as a list of {key, text} pairs.
   *
   * Stored as a LIST, not a keyed map: the editor's row keys contain dots
   * (component_tree.<uuid>.inputs.text) and config object keys cannot.
   *
   * @var array<int, array{key: string, text: string}>
   */
  protected array $source = [];

  /**
   * Builds the entity ID from a target langcode + translated config name.
   */
  public static function makeId(string $langcode, string $config_name): string {
    return $langcode . '.' . $config_name;
  }

  /**
   * The stored source strings, keyed by dotted row key.
   *
   * @return array<string, string>
   *   Source text, keyed by dotted row key.
   */
  public function getSource(): array {
    $map = [];
    foreach ($this->source as $pair) {
      $map[$pair['key']] = $pair['text'];
    }
    return $map;
  }

  /**
   * Sets the stored source strings from a dotted-key => text map.
   *
   * @param array<string, string> $source
   *   Source strings keyed by dotted row key.
   */
  public function setSource(array $source): self {
    $this->source = [];
    foreach ($source as $key => $text) {
      $this->source[] = ['key' => $key, 'text' => $text];
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Depend on the translated config so the baseline is removed alongside it.
   */
  public function calculateDependencies(): self {
    parent::calculateDependencies();
    if ($this->config_name !== '') {
      $this->addDependency('config', $this->config_name);
    }
    return $this;
  }

}
