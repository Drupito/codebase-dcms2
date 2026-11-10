<?php

declare(strict_types=1);

namespace Drupal\canvas_translate\Service;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;

/**
 * Extracts and writes back translatable strings for component_tree fields.
 *
 * This is a tmgmt-free copy of the relevant logic in
 * \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor, which can only be loaded
 * when the tmgmt_content module is installed (it extends that module's
 * DefaultFieldProcessor). Canvas Translate needs the exact same extraction —
 * the shared, tmgmt-free heavy lifting lives in
 * ComponentInputsTranslatablesExtractor — but without the tmgmt dependency.
 *
 * UPSTREAM(canvas): This whole class (~146 lines) is a verbatim fork that
 * exists for ONE reason — ComponentTreeFieldProcessor `extends
 * DefaultFieldProcessor` (tmgmt_content), so it cannot be autoloaded without
 * tmgmt. None of its translation logic touches tmgmt; it only uses
 * ComponentInputsTranslatablesExtractor + TypedConfigManager (both already
 * public, tmgmt-free).
 *   Minimal Canvas change: move extractTranslatableData() / setTranslations() /
 *   asComponentInputsMapping() / writeNestedTranslationsToInputs() into a
 *   plain, injectable, tmgmt-free service (e.g. \Drupal\canvas\Translation\
 *   ComponentTreeTranslator) and make ComponentTreeFieldProcessor a thin tmgmt
 *   adapter that delegates to it. Then: delete this class, inject the Canvas
 *   service directly. Net here: −146 lines, and the byte-compat drift risk
 *   between the two copies disappears (single source of truth). This is the
 *   highest-value upstream ask.
 *
 * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor
 */
final class TranslatableFieldGlue {

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ComponentInputsTranslatablesExtractor $componentInputsTranslatablesExtractor,
  ) {}

  /**
   * Extracts translatable data for a component_tree field.
   *
   * @return array
   *   A structure keyed by field item delta; each delta holds a nested tree of
   *   translatable leaves: ['#label' => …, '#text' => …, '#translate' => TRUE,
   *   '#format' => …]. The top level also carries a '#label' for the field.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::extractTranslatableData()
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $items = [];
    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $mapping = $this->asComponentInputsMapping($item);
      $items[$delta] = $this->componentInputsTranslatablesExtractor->extractTranslatables($mapping, $item->getInputs() ?? []);
    }
    return ['#label' => $field->getFieldDefinition()->getLabel()] + $items;
  }

  /**
   * Writes a flat draft values map's component strings onto a component field.
   *
   * Translates the editor's dotted keys (`components.<delta>.<path…>`) into the
   * TMGMT-shaped tree ::setTranslations() expects and applies it. Empty values
   * are skipped (source fallback), and only translatable leaves are written —
   * the structural tree of $field is left as-is, so applying a draft onto the
   * current stored translation never regresses structure changed since the
   * draft was saved.
   *
   * @param array<string, string> $values
   *   The editor's flat values map; only `components.*` keys are consumed.
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The target translation's `components` field.
   */
  public function applyValuesToComponents(array $values, FieldItemListInterface $field): void {
    $field_data = [];
    foreach ($values as $key => $text) {
      if (!\str_starts_with($key, 'components.') || $text === '') {
        continue;
      }
      $parts = \explode('.', \substr($key, \strlen('components.')));
      $delta = (int) \array_shift($parts);
      NestedArray::setValue(
        $field_data,
        [$delta, ...$parts],
        ['#translate' => TRUE, '#translation' => ['#text' => $text]],
      );
    }
    if ($field_data) {
      $this->setTranslations($field_data, $field);
    }
  }

  /**
   * Writes translations from a TMGMT-shaped data tree back into a field.
   *
   * Each translatable leaf in $field_data must carry a
   * #translation['#text'] value. Only the leaves present in $field_data are
   * touched; every other input of every component instance is preserved.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::setTranslations()
   */
  public function setTranslations(array $field_data, FieldItemListInterface $field): void {
    foreach (Element::children($field_data) as $delta) {
      $item_data = $field_data[$delta];
      if (!$field->offsetExists($delta)) {
        continue;
      }
      $item = $field->offsetGet($delta);
      \assert($item instanceof ComponentTreeItem);
      $inputs = $item->getInputs() ?? [];

      $changed = FALSE;
      foreach (Element::children($item_data) as $prop_key) {
        $found = FALSE;
        $inputs[$prop_key] = self::writeNestedTranslationsToInputs($item_data[$prop_key], $inputs[$prop_key] ?? NULL, $found);
        if ($found) {
          $changed = TRUE;
        }
      }

      if ($changed) {
        // Respect the `inputs` order dictated by the ComponentSource plugin.
        $config_schema_order = $this->asComponentInputsMapping($item)->getValidKeys();
        $inputs_in_schema_order = \array_replace(
          \array_fill_keys($config_schema_order, NULL),
          \array_intersect_key($inputs, \array_flip($config_schema_order)),
        );
        $item->setInput(\array_filter($inputs_in_schema_order, static fn($v) => $v !== NULL));
      }
    }
  }

  /**
   * Gets a ComponentTreeItem's `inputs` property as equivalent Typed Config.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::asComponentInputsMapping()
   */
  private function asComponentInputsMapping(ComponentTreeItem $item): ComponentInputsMapping {
    $values = $item->getValue();
    // For content fields `inputs` is a JSON string; for config-defined
    // component trees it is already an array. Tolerate both.
    if (\is_string($values['inputs'])) {
      $values['inputs'] = \json_decode($values['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR);
    }
    $parent_definition = ['type' => 'canvas.component_tree_node'];
    $name = (string) $item->getName();
    $field_value = $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($parent_definition, $values, $name),
      $values,
      $name,
    );
    $definition = [
      'type' => 'mapping',
      'class' => ComponentInputsMapping::class,
      'label' => 'Input values for each component instance in the component tree',
    ];
    $inputs = $item->getInputs();
    /** @var \Drupal\canvas\Config\Schema\ComponentInputsMapping */
    return $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($definition, $inputs, 'inputs', $field_value),
      $inputs,
      'inputs',
      $field_value,
    );
  }

  /**
   * Recursively writes translated leaves back into a nested input value.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::writeNestedTranslationsToInputs()
   */
  private static function writeNestedTranslationsToInputs(array $tmgmt_data, mixed $existing, bool &$found): mixed {
    if (\array_key_exists('#translate', $tmgmt_data) && $tmgmt_data['#translate'] === TRUE) {
      \assert(\array_key_exists('#translation', $tmgmt_data));
      \assert(\array_key_exists('#text', $tmgmt_data['#translation']));
      $found = TRUE;
      return $tmgmt_data['#translation']['#text'];
    }
    $result = \is_array($existing) ? $existing : [];
    foreach (Element::children($tmgmt_data) as $key) {
      $result[$key] = self::writeNestedTranslationsToInputs($tmgmt_data[$key], $result[$key] ?? NULL, $found);
    }
    return $result;
  }

}
