<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the renderer on the edges: empty, too few, too many, and mixed data.
 */
#[CoversClass(ViewTemplateRenderer::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRendererEdgeCasesTest extends ViewTemplateKernelTestBase {

  private const string REPEATER = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;

  private const string ITEM = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID;

  private const string CONDITION = ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID;

  private const string FIELD = ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID;

  private const string LINK = ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID;

  private const array ROWS = ['ROW_A', 'ROW_B', 'ROW_C'];

  /**
   * Renders a tree with the given data to a string.
   *
   * @param array<int, array<string, mixed>> $tree
   *   The tree items.
   * @param \Drupal\canvas_view_template\Render\ViewRenderData $data
   *   What the view produced.
   */
  private function renderTree(array $tree, ViewRenderData $data): string {
    $template = ViewTemplate::create(['id' => 'edge', 'label' => 'Edge', 'component_tree' => $tree]);
    $result = $this->renderWithData($template, $data);
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * Builds a repeater with "View item" in it.
   *
   * @param array<string, mixed> $inputs
   *   The repeater's settings.
   *
   * @return array<int, array<string, mixed>>
   *   The tree items, repeater first.
   */
  private function repeaterTree(array $inputs = []): array {
    $repeater = $this->treeItem(self::REPEATER, inputs: $inputs);
    return [$repeater, $this->treeItem(self::ITEM, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, [])];
  }

  /**
   * Builds a same-tab "View item link" inside a repeater.
   *
   * @param array<string, mixed> $repeater
   *   The repeater's tree item.
   *
   * @return array<string, mixed>
   *   The link's tree item.
   */
  private function linkIn(array $repeater): array {
    $inputs = [ViewTemplateItemLink::INPUT_OPEN => ViewTemplateItemLink::OPEN_SAME];
    return $this->treeItem(self::LINK, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, $inputs);
  }

  /**
   * Asserts which of the rows A, B and C are in the output.
   *
   * @param array<int, string> $expected
   *   The row names that must show, such as `['A', 'C']`.
   * @param string $rendered
   *   The rendered output.
   */
  private static function assertRowsShown(array $expected, string $rendered): void {
    foreach (['A', 'B', 'C', 'D'] as $name) {
      if (\in_array($name, $expected, TRUE)) {
        self::assertStringContainsString('ROW_' . $name, $rendered);
      }
      else {
        self::assertStringNotContainsString('ROW_' . $name, $rendered);
      }
    }
  }

  /**
   * Settings stored as strings or junk render like their whole-number values.
   *
   * A config import or the API can store what validation never saw, and a
   * front-end page must not fatal on it.
   *
   * @param array<string, mixed> $stored
   *   The settings as stored.
   * @param array<string, int|string> $same_as
   *   The settings they must behave like.
   */
  #[DataProvider('storedLikeNumbers')]
  public function testStoredSettingsOfTheWrongTypeBehaveLikeNumbers(array $stored, array $same_as): void {
    $data = new ViewRenderData(rowRenders: self::ROWS, currentPage: 1);
    $expected = $this->renderTree($this->repeaterTree($same_as), $data);
    $rendered = $this->renderTree($this->repeaterTree($stored), $data);
    self::assertSame($expected, $rendered);
  }

  /**
   * Wrongly typed settings and the settings they must behave like.
   *
   * The pager is on page 2 (index 1) in these tests.
   *
   * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
   *   The cases.
   */
  public static function storedLikeNumbers(): array {
    $offset = ViewTemplateRepeater::INPUT_OFFSET;
    $limit = ViewTemplateRepeater::INPUT_LIMIT;
    $mode = ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE;
    $page = ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE;
    return [
      'offset and limit as strings' => [[$offset => '1', $limit => '1'], [$offset => 1, $limit => 1]],
      'only page 2 as a string' => [
        [$offset => 0, $mode => 'only', $page => '2'],
        [$offset => 0, $mode => 'only', $page => 2],
      ],
      'except page 2 as a string' => [
        [$offset => 0, $mode => 'except', $page => '2'],
        [$offset => 0, $mode => 'except', $page => 2],
      ],
      'made-up mode is all pages' => [[$offset => 0, $mode => 'bogus', $page => '2'], [$offset => 0]],
      'negative offset is zero' => [[$offset => -4], [$offset => 0]],
      'junk limit is no limit' => [[$offset => 0, $limit => 'abc'], [$offset => 0]],
      'offset as an array is zero' => [[$offset => [1]], [$offset => 0]],
    ];
  }

  /**
   * An offset past the last row shows nothing, and does not fail.
   */
  public function testOffsetBeyondTheRowsShowsNothing(): void {
    foreach ([3, 4, 50] as $offset) {
      $rendered = $this->renderTree($this->repeaterTree([ViewTemplateRepeater::INPUT_OFFSET => $offset]), new ViewRenderData(rowRenders: self::ROWS));
      self::assertRowsShown([], $rendered);
    }
  }

  /**
   * A limit and offset that reach past the end show what is left.
   */
  #[DataProvider('slices')]
  public function testSlices(array $inputs, array $expected): void {
    $rendered = $this->renderTree($this->repeaterTree($inputs), new ViewRenderData(rowRenders: self::ROWS));
    self::assertRowsShown($expected, $rendered);
  }

  /**
   * Settings and the rows they show, out of A, B and C.
   *
   * @return array<string, array{0: array<string, int>, 1: array<int, string>}>
   *   The cases.
   */
  public static function slices(): array {
    $limit = ViewTemplateRepeater::INPUT_LIMIT;
    $offset = ViewTemplateRepeater::INPUT_OFFSET;
    return [
      'limit larger than the rows' => [[$limit => 10], ['A', 'B', 'C']],
      'limit equal to the rows' => [[$limit => 3], ['A', 'B', 'C']],
      'limit past the end from an offset' => [[$limit => 10, $offset => 2], ['C']],
      'limit 0 means no limit' => [[$limit => 0], ['A', 'B', 'C']],
      'limit 0 after an offset' => [[$limit => 0, $offset => 2], ['C']],
      'no limit at all' => [[], ['A', 'B', 'C']],
      'limit 1 from the middle' => [[$limit => 1, $offset => 1], ['B']],
    ];
  }

  /**
   * With no rows a repeater shows nothing, and the rest of the page remains.
   */
  public function testNoRowsStillRendersTheRestOfTheTemplate(): void {
    $tree = [
      ...$this->repeaterTree(),
      $this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
    ];
    $rendered = $this->renderTree($tree, new ViewRenderData(pager: ['#markup' => 'THE_PAGER']));
    self::assertStringContainsString('THE_PAGER', $rendered);
    self::assertStringNotContainsString('ROW_', $rendered);
  }

  /**
   * A repeater with nothing in its slot shows nothing, even with rows.
   */
  public function testRepeaterWithAnEmptySubtreeShowsNothing(): void {
    $tree = [
      $this->treeItem(self::REPEATER, inputs: []),
      $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
    ];
    $rendered = $this->renderTree($tree, new ViewRenderData(rows: ['#markup' => 'THE_ROWS'], rowRenders: self::ROWS));
    self::assertStringContainsString('THE_ROWS', $rendered);
    self::assertStringNotContainsString('ROW_', $rendered);
  }

  /**
   * A condition inside a repeater hides every item when it is not met.
   */
  public function testConditionInsideRepeaterWithNoMatchHidesEveryItem(): void {
    $repeater = $this->treeItem(self::REPEATER, inputs: []);
    $has_header = [ViewTemplateCondition::INPUT_CONDITION => ViewConditions::HAS_HEADER];
    $condition = $this->treeItem(self::CONDITION, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, $has_header);
    $item = $this->treeItem(self::ITEM, $condition['uuid'], ViewTemplateCondition::SLOT_NAME, []);
    $tree = [$repeater, $condition, $item];

    self::assertRowsShown([], $this->renderTree($tree, new ViewRenderData(rowRenders: self::ROWS)));
    $with_header = new ViewRenderData(rowRenders: self::ROWS, areas: ['header' => ['#markup' => 'HEADER']]);
    self::assertRowsShown(['A', 'B', 'C'], $this->renderTree($tree, $with_header));
  }

  /**
   * An item with no page (such as one the user cannot view) has no link.
   */
  public function testRowsWithoutPageHaveNoLinkAndKeepPlace(): void {
    $repeater = $this->treeItem(self::REPEATER, inputs: [
      ViewTemplateRepeater::INPUT_OFFSET => 1,
      ViewTemplateRepeater::INPUT_LIMIT => 2,
    ]);
    $link = $this->linkIn($repeater);
    $item = $this->treeItem(self::ITEM, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, []);
    $tree = [$repeater, $link, $item];

    $rows = [...self::ROWS, 'ROW_D'];
    $urls = ['/node/1', NULL, '/node/3', '/node/4'];
    $rendered = $this->renderTree($tree, new ViewRenderData(rowRenders: $rows, itemUrls: $urls));
    self::assertRowsShown(['B', 'C'], $rendered);
    self::assertStringContainsString('<a href="/node/3">', $rendered);
    self::assertSame(1, \substr_count($rendered, '<a '));
    foreach (['/node/1', '/node/4'] as $skipped) {
      self::assertStringNotContainsString($skipped, $rendered);
    }
  }

  /**
   * Fewer URLs than rows leaves the later rows without links, without failing.
   */
  public function testFewerUrlsThanRowsIsTolerated(): void {
    $repeater = $this->treeItem(self::REPEATER, inputs: []);
    $link = $this->linkIn($repeater);
    $item = $this->treeItem(self::ITEM, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, []);

    $data = new ViewRenderData(rowRenders: self::ROWS, itemUrls: ['/node/1']);
    $rendered = $this->renderTree([$repeater, $link, $item], $data);
    self::assertRowsShown(['A', 'B', 'C'], $rendered);
    self::assertSame(1, \substr_count($rendered, '<a '));
  }

  /**
   * The page condition, by mode, on each page.
   */
  #[DataProvider('pageConditions')]
  public function testPageCondition(string $mode, int $page, int $current_page, bool $shown): void {
    $tree = $this->repeaterTree([
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => $mode,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => $page,
    ]);
    $rendered = $this->renderTree($tree, new ViewRenderData(rowRenders: self::ROWS, currentPage: $current_page));
    self::assertRowsShown($shown ? ['A', 'B', 'C'] : [], $rendered);
  }

  /**
   * Mode, the 1-based page, the 0-based current page, and whether it shows.
   *
   * @return array<string, array{0: string, 1: int, 2: int, 3: bool}>
   *   The cases.
   */
  public static function pageConditions(): array {
    $only = ViewTemplateRepeater::PAGE_CONDITION_ONLY;
    $except = ViewTemplateRepeater::PAGE_CONDITION_EXCEPT;
    return [
      'only page 2, on page 1' => [$only, 2, 0, FALSE],
      'only page 2, on page 2' => [$only, 2, 1, TRUE],
      'only page 2, on page 3' => [$only, 2, 2, FALSE],
      'except page 2, on page 1' => [$except, 2, 0, TRUE],
      'except page 2, on page 2' => [$except, 2, 1, FALSE],
      'except page 2, on page 3' => [$except, 2, 2, TRUE],
      'only a page past the last' => [$only, 9, 0, FALSE],
      'except a page past the last' => [$except, 9, 0, TRUE],
    ];
  }

  /**
   * Fields, links and rows are cut the same way, so they stay together.
   */
  public function testFieldsAndUrlsAreSlicedWithTheirRows(): void {
    $repeater = $this->treeItem(self::REPEATER, inputs: [
      ViewTemplateRepeater::INPUT_OFFSET => 1,
      ViewTemplateRepeater::INPUT_LIMIT => 1,
    ]);
    $link = $this->linkIn($repeater);
    $field = $this->treeItem(self::FIELD, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, [
      ViewTemplateField::INPUT_FIELD => 'title',
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => ViewTemplateField::LABEL_HIDDEN,
    ]);
    $data = new ViewRenderData(
      rowRenders: self::ROWS,
      fieldData: [
        'labels' => ['title' => 'Title'],
        'rows' => [
          ['title' => ['#markup' => 'T0']],
          ['title' => ['#markup' => 'T1']],
          ['title' => ['#markup' => 'T2']],
        ],
      ],
      itemUrls: ['/node/0', '/node/1', '/node/2'],
    );

    $rendered = $this->renderTree([$repeater, $link, $field], $data);
    self::assertStringContainsString('T1', $rendered);
    self::assertStringContainsString('<a href="/node/1">', $rendered);
    foreach (['T0', 'T2', '/node/0', '/node/2'] as $other) {
      self::assertStringNotContainsString($other, $rendered);
    }
  }

}
