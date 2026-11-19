<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateAtMostOneRowsConstraint;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateNoNestedRepeaterConstraint;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateRepeaterContentsConstraint;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Validation;

/**
 * Checks the tree constraints on many random trees against a simple oracle.
 *
 * Every tree is built from its own seed (the base seed plus its number), so a
 * failure message is enough to rebuild the tree.
 */
#[Group('canvas_view_template')]
final class ViewTemplateTreeFuzzTest extends ViewTemplateKernelTestBase {

  private const int SEED = 20260101;

  private const int TREES = 300;

  private const string REPEATER = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;

  private const string ROWS = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;

  /**
   * Random trees break exactly the rules the oracle says they break.
   */
  public function testConstraintsAgreeWithOracle(): void {
    $validator = Validation::createValidator();
    $constraints = [
      new ViewTemplateAtMostOneRowsConstraint(),
      new ViewTemplateNoNestedRepeaterConstraint(),
      new ViewTemplateRepeaterContentsConstraint(),
    ];
    $totals = ['rows' => 0, 'nested' => 0, 'contents' => 0, 'valid' => 0];

    for ($number = 0; $number < self::TREES; $number++) {
      $tree = $this->randomTree(self::SEED + $number);
      $expected = self::oracle($tree);

      $actual = ['rows' => 0, 'nested' => 0, 'contents' => 0];
      foreach ($validator->validate($tree, $constraints) as $violation) {
        $key = match (TRUE) {
          $violation->getConstraint() instanceof ViewTemplateAtMostOneRowsConstraint => 'rows',
          $violation->getConstraint() instanceof ViewTemplateNoNestedRepeaterConstraint => 'nested',
          default => 'contents',
        };
        $actual[$key]++;
      }

      self::assertSame($expected, $actual, \sprintf("Tree %d (seed %d) breaks the rules differently than expected:\n%s", $number, self::SEED + $number, self::describe($tree)));
      foreach ($expected as $key => $count) {
        $totals[$key] += $count;
      }
      $totals['valid'] += \array_sum($expected) === 0 ? 1 : 0;
    }

    // The trees must exercise every rule, and also include valid ones.
    foreach ($totals as $key => $total) {
      self::assertGreaterThan(0, $total, \sprintf('No random tree exercised "%s".', $key));
    }
  }

  /**
   * Builds a random tree, deterministic for a seed.
   *
   * Items may sit at the root, inside an earlier container, or point at a
   * parent that does not exist; the order is shuffled.
   *
   * @return array<int, array<string, mixed>>
   *   The tree items.
   */
  private function randomTree(int $seed): array {
    \mt_srand($seed);
    $placeholder = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.';
    $ids = [
      self::ROWS,
      $placeholder . ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_HEADER_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_FOOTER_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_MORE_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_ATTACHMENTS_LOCAL_ID,
      $placeholder . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID,
      ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID,
      ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID,
      ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID,
      ViewTemplateFeedIcons::SOURCE_PLUGIN_ID . '.' . ViewTemplateFeedIcons::LOCAL_ID,
      ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID,
      // Containers are listed again so they are common.
      self::REPEATER,
      self::REPEATER,
      ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID,
      ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID,
    ];
    $slots = [
      self::REPEATER => ViewTemplateRepeater::SLOT_NAME,
      ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID => ViewTemplateCondition::SLOT_NAME,
      ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID => ViewTemplateItemLink::SLOT_NAME,
      ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID => ViewTemplateEmpty::SLOT_NAME,
    ];

    $items = [];
    /** @var array<int, array{uuid: string, slot: string}> $containers */
    $containers = [];
    $count = \mt_rand(0, 10);
    for ($index = 0; $index < $count; $index++) {
      $component_id = $ids[\mt_rand(0, \count($ids) - 1)];
      $uuid = \sprintf('00000000-0000-4000-8000-%012d', $index + 1);
      $roll = \mt_rand(1, 100);
      if ($roll <= 5) {
        $item = $this->treeItem($component_id, '99999999-9999-4999-8999-999999999999', ViewTemplateRepeater::SLOT_NAME, [], $uuid);
      }
      elseif ($roll <= 50 || $containers === []) {
        $item = $this->treeItem($component_id, inputs: [], uuid: $uuid);
      }
      else {
        $parent = $containers[\mt_rand(0, \count($containers) - 1)];
        $item = $this->treeItem($component_id, $parent['uuid'], $parent['slot'], [], $uuid);
      }
      if (isset($slots[$component_id])) {
        $containers[] = ['uuid' => $uuid, 'slot' => $slots[$component_id]];
      }
      $items[] = $item;
    }
    \shuffle($items);
    return $items;
  }

  /**
   * Works out the violations a tree should have, without the module's code.
   *
   * @param array<int, array<string, mixed>> $items
   *   The tree items.
   *
   * @return array{rows: int, nested: int, contents: int}
   *   How many violations each constraint should report.
   */
  private static function oracle(array $items): array {
    // Written out here on purpose, so a change to the module's own list fails.
    $whole_view = [
      'canvas_view_template_placeholder.view_rows',
      'canvas_view_template_placeholder.view_exposed_filters',
      'canvas_view_template_placeholder.view_pager',
      'canvas_view_template_placeholder.view_header',
      'canvas_view_template_placeholder.view_footer',
      'canvas_view_template_placeholder.view_more',
      'canvas_view_template_placeholder.view_attachments',
      'canvas_view_template_empty.view_empty',
      'canvas_view_template_title.view_title',
      'canvas_view_template_summary.view_summary',
      'canvas_view_template_feed_icons.view_feed_icons',
    ];
    $by_uuid = [];
    foreach ($items as $item) {
      $by_uuid[$item['uuid']] = $item;
    }
    $in_repeater = static function (array $item) use ($by_uuid): bool {
      $parent = $item['parent_uuid'] ?? NULL;
      for ($depth = 0; $parent !== NULL && isset($by_uuid[$parent]) && $depth < 50; $depth++) {
        if ($by_uuid[$parent]['component_id'] === self::REPEATER) {
          return TRUE;
        }
        $parent = $by_uuid[$parent]['parent_uuid'] ?? NULL;
      }
      return FALSE;
    };

    $rows = 0;
    $nested = 0;
    $contents = 0;
    foreach ($items as $item) {
      $rows += $item['component_id'] === self::ROWS ? 1 : 0;
      if ($in_repeater($item)) {
        $nested += $item['component_id'] === self::REPEATER ? 1 : 0;
        $contents += \in_array($item['component_id'], $whole_view, TRUE) ? 1 : 0;
      }
    }
    return ['rows' => $rows > 1 ? 1 : 0, 'nested' => $nested, 'contents' => $contents];
  }

  /**
   * Describes a tree for a failure message.
   *
   * @param array<int, array<string, mixed>> $items
   *   The tree items.
   */
  private static function describe(array $items): string {
    return \implode("\n", \array_map(
      static fn (array $item): string => \sprintf('  %s %s parent=%s', \substr($item['uuid'], -3), $item['component_id'], isset($item['parent_uuid']) ? \substr($item['parent_uuid'], -3) : '-'),
      $items,
    ));
  }

}
