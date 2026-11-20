<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;

/**
 * Renders a view template's component tree with a View's output injected.
 *
 * Same mechanism as CanvasPageVariant::renderComponentTree(): placeholder
 * components suspend a PHP Fiber and are resumed with the real content.
 *
 * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::renderComponentTree()
 */
final class ViewTemplateRenderer {

  /**
   * The Component config entity ids: "source plugin id.local id".
   */
  public const string VIEW_CONDITION_COMPONENT_ID = ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID;

  public const string VIEW_ITEM_LINK_COMPONENT_ID = ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID;

  public const string VIEW_EMPTY_COMPONENT_ID = ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID;

  public const string VIEW_SUMMARY_COMPONENT_ID = ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID;

  public const string VIEW_FIELD_COMPONENT_ID = ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID;

  public const string VIEW_REPEATER_COMPONENT_ID = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;

  /**
   * Renders a view template with a View's output injected.
   */
  public function render(ViewTemplate $view_template, ViewRenderData $data): array {
    $rows = $data->rows;
    $areas = $data->areas;
    $row_set = RowSet::fromData($data);
    $current_page = $data->currentPage;
    $summary = $data->summary;
    $facts = $data->facts;
    $exposed = $data->exposed;
    $pager = $data->pager;
    $no_rows = $rows === [] || $rows === '';
    $rows = self::toRenderArray($rows);
    $exposed = self::toRenderArray($exposed);
    $pager = self::toRenderArray($pager);
    $area = static fn (string $key): array => self::toRenderArray($areas[$key] ?? []);

    $tree_items = $view_template->getComponentTree()->getValue();
    $empty_placed = FALSE;
    $with_children = [];
    foreach ($tree_items as $item) {
      $empty_placed = $empty_placed || $item['component_id'] === self::VIEW_EMPTY_COMPONENT_ID;
      if (isset($item['parent_uuid'])) {
        $with_children[$item['parent_uuid']] = TRUE;
      }
    }
    // "View no results" resumes with [] when there are rows, else NULL (render
    // its slot) or the Views empty area if its slot is empty. "View condition"
    // resumes with NULL (render the slot) when met, else [].
    $empty_values = [];
    $condition_values = [];
    foreach ($tree_items as $item) {
      if ($item['component_id'] === self::VIEW_EMPTY_COMPONENT_ID) {
        $empty_values[$item['uuid']] = !$no_rows ? [] : (isset($with_children[$item['uuid']]) ? NULL : $area('empty'));
      }
      elseif ($item['component_id'] === self::VIEW_CONDITION_COMPONENT_ID) {
        $condition = $item['inputs'][ViewTemplateCondition::INPUT_CONDITION] ?? ViewConditions::DEFAULT;
        $condition_values[$item['uuid']] = ViewConditions::isMet(\is_string($condition) ? $condition : ViewConditions::DEFAULT, $facts, $areas) ? NULL : [];
      }
    }
    // `array_key_exists()`, not `??`: NULL is a real value here.
    $empty_value = static fn (?string $uuid): mixed => $uuid !== NULL && \array_key_exists($uuid, $empty_values) ? $empty_values[$uuid] : [];

    $repeater_outputs = self::computeRepeaterOutputs($view_template, $tree_items, $row_set, $current_page, $condition_values);
    // Outside a repeater, "View item" shows the first row.
    $first_row = $row_set->render(0);

    // Like Views' own template, "View rows" shows the empty text when there
    // are no rows, unless "View no results" is placed.
    $placeholder_values = [
      ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID => $no_rows && !$empty_placed ? $area('empty') : $rows,
      ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID => $exposed,
      ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID => $pager,
      ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID => $first_row,
      ViewTemplatePlaceholder::VIEW_HEADER_LOCAL_ID => $area('header'),
      ViewTemplatePlaceholder::VIEW_FOOTER_LOCAL_ID => $area('footer'),
      ViewTemplatePlaceholder::VIEW_MORE_LOCAL_ID => $area('more'),
      ViewTemplatePlaceholder::VIEW_ATTACHMENTS_LOCAL_ID => \array_filter([
        'before' => $area('attachment_before'),
        'after' => $area('attachment_after'),
      ]),
    ];

    $component_tree = $view_template->getComponentTree();
    $fiber = new \Fiber(fn() => $component_tree->toRenderable($view_template, FALSE));
    $component_instance = $fiber->start();
    while ($fiber->isSuspended()) {
      $component_instance = match (TRUE) {
        $component_instance instanceof ViewTemplatePlaceholder && \array_key_exists($component_instance->getSourceSpecificComponentId(), $placeholder_values) => $fiber->resume($placeholder_values[$component_instance->getSourceSpecificComponentId()]),
        $component_instance instanceof ViewTemplateRepeater => $fiber->resume($repeater_outputs[$component_instance->getCurrentComponentUuid()] ?? []),
        $component_instance instanceof ViewTemplateFeedIcons => $fiber->resume($area('feed_icons')),
        $component_instance instanceof ViewTemplateTitle => $fiber->resume($area('title')),
        $component_instance instanceof ViewTemplateSummary => $fiber->resume($summary),
        // Outside a repeater, a field shows the first item's value.
        $component_instance instanceof ViewTemplateField => $fiber->resume($row_set->fieldValue(0, $component_instance->getCurrentFieldId())),
        $component_instance instanceof ViewTemplateEmpty => $fiber->resume($empty_value($component_instance->getCurrentComponentUuid())),
        $component_instance instanceof ViewTemplateCondition => $fiber->resume($condition_values[$component_instance->getCurrentComponentUuid()] ?? NULL),
        // Outside a repeater, an item link goes to the first item.
        $component_instance instanceof ViewTemplateItemLink => $fiber->resume(['url' => $row_set->url(0)]),
        // Suspended elsewhere (e.g. while loading entities): just continue.
        default => $fiber->resume(),
      };
    }
    \assert($fiber->isTerminated());
    return $fiber->getReturn();
  }

  /**
   * Renders every "View repeater"'s slot once per row.
   *
   * Canvas cannot repeat a slot, so each repeater's slot subtree is extracted
   * and re-rendered in its own Fiber pass per row.
   *
   * @param \Drupal\canvas_view_template\Entity\ViewTemplate $view_template
   *   The view template being rendered.
   * @param array $tree_items
   *   The raw component tree items.
   * @param \Drupal\canvas_view_template\Render\RowSet $rows
   *   All rows.
   * @param int $current_page
   *   The pager's current page, 0-based.
   * @param array<string, array|null> $condition_values
   *   What each "View condition" resumes with, by tree UUID.
   *
   * @return array<string, array>
   *   Each repeater's render, keyed by its tree UUID.
   */
  private static function computeRepeaterOutputs(ViewTemplate $view_template, array $tree_items, RowSet $rows, int $current_page, array $condition_values): array {
    $outputs = [];
    foreach ($tree_items as $item) {
      if ($item['component_id'] === self::VIEW_REPEATER_COMPONENT_ID) {
        $outputs[$item['uuid']] = self::renderRepeater($view_template, $tree_items, $item, $rows, $current_page, $condition_values);
      }
    }
    return $outputs;
  }

  /**
   * Renders a single "View repeater" tree item's slot once per row.
   *
   * @param \Drupal\canvas_view_template\Entity\ViewTemplate $view_template
   *   The view template being rendered.
   * @param array $tree_items
   *   The raw component tree items.
   * @param array $repeater_item
   *   The "View repeater" tree item.
   * @param \Drupal\canvas_view_template\Render\RowSet $rows
   *   All rows; this repeater's limit and offset are applied here.
   * @param int $current_page
   *   The pager's current page, 0-based.
   * @param array<string, array|null> $condition_values
   *   What each "View condition" resumes with, by tree UUID.
   *
   * @return array
   *   One copy of the slot subtree per row; empty if the slot is empty or the
   *   page condition excludes $current_page.
   */
  private static function renderRepeater(ViewTemplate $view_template, array $tree_items, array $repeater_item, RowSet $rows, int $current_page, array $condition_values): array {
    $repeater_uuid = $repeater_item['uuid'];
    // Stored values may be strings or junk (API, config import): read safely.
    ['limit' => $limit, 'offset' => $offset, 'mode' => $page_condition_mode, 'page' => $page_condition_page] = ViewTemplateRepeater::normalizeSettings($repeater_item['inputs'] ?? []);
    if (self::pageConditionExcludesPage($page_condition_mode, $page_condition_page, $current_page)) {
      return [];
    }

    $subtree_items = self::collectSlotSubtree($tree_items, $repeater_uuid, ViewTemplateRepeater::SLOT_NAME);
    return self::renderRowsThroughSubtree($view_template, $subtree_items, $rows->slice($offset, $limit), $condition_values);
  }

  /**
   * Checks whether a page condition excludes the given pager page.
   *
   * @param string $mode
   *   One of the ViewTemplateRepeater::PAGE_CONDITION_* constants.
   * @param int|null $page
   *   The condition's 1-based page number; NULL means no condition.
   * @param int $current_page
   *   The pager's current page, 0-based.
   */
  private static function pageConditionExcludesPage(string $mode, ?int $page, int $current_page): bool {
    if ($page === NULL) {
      return FALSE;
    }
    // $page is 1-based; $current_page is 0-based.
    $current_page_1_based = $current_page + 1;
    $excluded_by_only = $mode === ViewTemplateRepeater::PAGE_CONDITION_ONLY && $current_page_1_based !== $page;
    $excluded_by_except = $mode === ViewTemplateRepeater::PAGE_CONDITION_EXCEPT && $current_page_1_based === $page;
    return $excluded_by_only || $excluded_by_except;
  }

  /**
   * Collects a slot's direct children and all their descendants.
   *
   * The direct children are re-rooted (parent_uuid NULL) for a standalone
   * render.
   *
   * @param array $tree_items
   *   The raw component tree items.
   * @param string $parent_uuid
   *   The slot-bearing component's tree UUID.
   * @param string $slot_name
   *   The slot's machine name.
   *
   * @return array
   *   The subtree items; empty if nothing is placed in the slot.
   */
  private static function collectSlotSubtree(array $tree_items, string $parent_uuid, string $slot_name): array {
    $slot_root_uuids = [];
    $subtree_items = [];
    foreach ($tree_items as $item) {
      if (($item['parent_uuid'] ?? NULL) === $parent_uuid && ($item['slot'] ?? NULL) === $slot_name) {
        $slot_root_uuids[$item['uuid']] = TRUE;
        $subtree_items[] = ['parent_uuid' => NULL, 'slot' => NULL] + $item;
      }
    }
    $descendant_uuids = $slot_root_uuids;
    // Sweep until stable; parents are not guaranteed to come first.
    do {
      $added = FALSE;
      foreach ($tree_items as $item) {
        $item_parent_uuid = $item['parent_uuid'] ?? NULL;
        if ($item_parent_uuid !== NULL && !isset($descendant_uuids[$item['uuid']]) && isset($descendant_uuids[$item_parent_uuid])) {
          $descendant_uuids[$item['uuid']] = TRUE;
          $subtree_items[] = $item;
          $added = TRUE;
        }
      }
    } while ($added);
    return $subtree_items;
  }

  /**
   * Renders a subtree once per row, resuming "View item" with each.
   *
   * @param \Drupal\canvas_view_template\Entity\ViewTemplate $view_template
   *   The view template being rendered.
   * @param array $subtree_items
   *   The re-rooted subtree items, from ::collectSlotSubtree().
   * @param \Drupal\canvas_view_template\Render\RowSet $rows
   *   The rows to render, already limited and offset.
   * @param array<string, array|null> $condition_values
   *   What each "View condition" resumes with, by tree UUID.
   *
   * @return array
   *   One copy of the subtree per row; empty if there is no subtree.
   */
  private static function renderRowsThroughSubtree(ViewTemplate $view_template, array $subtree_items, RowSet $rows, array $condition_values): array {
    if ($subtree_items === []) {
      return [];
    }
    $assembled = [];
    $subtree = $view_template->getComponentSubtree($subtree_items);
    foreach (\array_keys($rows->renders) as $index) {
      $fiber = new \Fiber(fn() => $subtree->toRenderable($view_template, FALSE));
      $component_instance = $fiber->start();
      $row = $rows->render($index);
      while ($fiber->isSuspended()) {
        $component_instance = match (TRUE) {
          $component_instance instanceof ViewTemplatePlaceholder && $component_instance->getSourceSpecificComponentId() === ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID => $fiber->resume($row),
          $component_instance instanceof ViewTemplateField => $fiber->resume($rows->fieldValue($index, $component_instance->getCurrentFieldId())),
          $component_instance instanceof ViewTemplateCondition => $fiber->resume($condition_values[$component_instance->getCurrentComponentUuid()] ?? NULL),
          $component_instance instanceof ViewTemplateItemLink => $fiber->resume(['url' => $rows->url($index)]),
          default => $fiber->resume(),
        };
      }
      \assert($fiber->isTerminated());
      $assembled['repeated_item_' . $index] = $fiber->getReturn();
    }
    return $assembled;
  }

  /**
   * Normalizes a Views render fragment that may be a plain string.
   */
  private static function toRenderArray(array|string $value): array {
    return \is_array($value) ? $value : ['#markup' => $value];
  }

}
