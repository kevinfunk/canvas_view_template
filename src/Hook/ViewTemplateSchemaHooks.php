<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Hook;

use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;

/**
 * Lets Canvas's component schema accept this module's component sources.
 *
 * Canvas lists the component sources its config schema allows, and will not
 * make that a public API until drupal.org/i/3520484. Without this, anything
 * that validates config (Config Inspector, a recipe, strict schema checks)
 * reports every shipped component as invalid, unless `canvas_dev_mode` removes
 * the whole list. This adds only this module's sources to it.
 *
 * @see \Drupal\canvas_dev_mode\Hook\UsePrivateApis::configSchemaInfoAlter()
 */
final class ViewTemplateSchemaHooks {

  /**
   * Implements hook_config_schema_info_alter().
   *
   * Does nothing if the list is not there: `canvas_dev_mode` removed it, or
   * Canvas changed the schema. The tests then show it.
   *
   * @param array<string, mixed> $definitions
   *   The config schema definitions.
   */
  #[Hook('config_schema_info_alter', order: Order::Last)]
  public static function configSchemaInfoAlter(array &$definitions): void {
    $choices = $definitions['canvas.component.*']['mapping']['source']['constraints']['Choice']['choices'] ?? NULL;
    if (!\is_array($choices)) {
      return;
    }
    $definitions['canvas.component.*']['mapping']['source']['constraints']['Choice']['choices'] = \array_values(\array_unique([
      ...$choices,
      ...self::sourcePluginIds(),
    ]));
  }

  /**
   * The component source plugin IDs this module provides.
   *
   * @return list<string>
   *   The IDs.
   */
  public static function sourcePluginIds(): array {
    return [
      ViewTemplateCondition::SOURCE_PLUGIN_ID,
      ViewTemplateEmpty::SOURCE_PLUGIN_ID,
      ViewTemplateFeedIcons::SOURCE_PLUGIN_ID,
      ViewTemplateField::SOURCE_PLUGIN_ID,
      ViewTemplateItemLink::SOURCE_PLUGIN_ID,
      ViewTemplatePlaceholder::SOURCE_PLUGIN_ID,
      ViewTemplateRepeater::SOURCE_PLUGIN_ID,
      ViewTemplateSummary::SOURCE_PLUGIN_ID,
      ViewTemplateTitle::SOURCE_PLUGIN_ID,
    ];
  }

}
