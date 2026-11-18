<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\views\ViewExecutable;

/**
 * Collects each result row's individually rendered fields.
 *
 * For "View field". Only Views using the "Fields" row style have individual
 * fields; for any other, nothing is collected.
 *
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField
 */
final class ViewFieldRenders {

  /**
   * Renders every field of every result row.
   *
   * @return array{rows: list<array<string, array>>, labels: array<string, string>}
   *   Each row's field render arrays (empty if no value), and the labels.
   */
  public static function collect(ViewExecutable $view): array {
    $style = $view->style_plugin;
    // ViewExecutable::$style_plugin is NULL until the View is built.
    // @phpstan-ignore booleanNot.alwaysFalse
    if (!$style || !$style->usesFields()) {
      return ['rows' => [], 'labels' => []];
    }
    $field_ids = [];
    $labels = [];
    foreach ($view->field as $id => $handler) {
      if (!empty($handler->options['exclude'])) {
        continue;
      }
      $field_ids[] = (string) $id;
      $labels[(string) $id] = (string) $handler->label();
    }
    $rows = [];
    foreach (\array_keys($view->result) as $index) {
      $row = [];
      foreach ($field_ids as $id) {
        $value = $style->getField($index, $id);
        $row[$id] = $value === NULL || (string) $value === '' ? [] : ['#markup' => $value];
      }
      $rows[] = $row;
    }
    return ['rows' => $rows, 'labels' => $labels];
  }

}
