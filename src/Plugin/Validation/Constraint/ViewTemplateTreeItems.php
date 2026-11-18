<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;

/**
 * Reads a view template's tree items for the validators, tolerating bad data.
 */
final class ViewTemplateTreeItems {

  /**
   * The full Component config entity id of "View repeater".
   */
  public const string VIEW_REPEATER_COMPONENT_ID = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;

  /**
   * The well-formed items of an item list, a config sequence, or NULL.
   *
   * @return list<array{uuid: string, component_id: string, parent_uuid: string|null}>
   *   Items with a string `uuid` and `component_id`; others are skipped.
   */
  public static function from(mixed $value): array {
    $items = match (TRUE) {
      $value instanceof ComponentTreeItemList => $value->getValue(),
      \is_array($value) => $value,
      default => [],
    };
    $clean = [];
    foreach ($items as $item) {
      if (!\is_array($item) || !\is_string($item['uuid'] ?? NULL) || !\is_string($item['component_id'] ?? NULL)) {
        continue;
      }
      $parent_uuid = $item['parent_uuid'] ?? NULL;
      $clean[] = [
        'uuid' => $item['uuid'],
        'component_id' => $item['component_id'],
        'parent_uuid' => \is_string($parent_uuid) ? $parent_uuid : NULL,
      ];
    }
    return $clean;
  }

  /**
   * The items that sit inside a "View repeater", at any depth.
   *
   * @param array<int, mixed> $items
   *   The tree's items, as stored.
   *
   * @return array<string, string>
   *   Component ids, keyed by item UUID.
   */
  public static function insideRepeater(array $items): array {
    $items = self::from($items);
    $by_uuid = \array_column($items, NULL, 'uuid');
    $inside = [];
    foreach ($items as $item) {
      // Walk up the parents; the seen list stops a malformed tree looping.
      $seen = [];
      $parent_uuid = $item['parent_uuid'];
      while ($parent_uuid !== NULL && isset($by_uuid[$parent_uuid]) && !isset($seen[$parent_uuid])) {
        $seen[$parent_uuid] = TRUE;
        if ($by_uuid[$parent_uuid]['component_id'] === self::VIEW_REPEATER_COMPONENT_ID) {
          $inside[$item['uuid']] = $item['component_id'];
          break;
        }
        $parent_uuid = $by_uuid[$parent_uuid]['parent_uuid'];
      }
    }
    return $inside;
  }

}
