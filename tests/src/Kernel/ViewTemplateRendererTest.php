<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Render\BubbleableMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Fiber-based dispatch that renders a view template.
 */
#[CoversClass(ViewTemplateRenderer::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRendererTest extends ViewTemplateKernelTestBase {

  /**
   * Rows, exposed filters, and pager are each injected independently.
   */
  public function testRowsExposedPagerInjection(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID),
      ],
    ]);

    $result = $this->container->get(ViewTemplateRenderer::class)->render(
      $view_template,
      ['#markup' => 'THE_ROWS'],
      ['#markup' => 'THE_EXPOSED_FORM'],
      ['#markup' => 'THE_PAGER'],
    );
    $rendered = (string) $this->renderer->renderInIsolation($result);

    self::assertStringContainsString('THE_ROWS', $rendered);
    self::assertStringContainsString('THE_EXPOSED_FORM', $rendered);
    self::assertStringContainsString('THE_PAGER', $rendered);
  }

  /**
   * The render output bubbles the view template's cache tag.
   */
  public function testRenderBubblesViewTemplateCacheTag(): void {
    $view_template = ViewTemplate::create([
      'id' => 'cache_test',
      'label' => 'Cache test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
    ]);
    $view_template->save();

    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, ['#markup' => 'ROWS'], '', '');
    $this->renderer->renderInIsolation($result);

    self::assertContains(
      $view_template->getCacheTags()[0],
      BubbleableMetadata::createFromRenderArray($result)->getCacheTags(),
    );
  }

  /**
   * Header, footer and title go where their placeholders are placed.
   */
  public function testHeaderFooterEmptyTitleInjection(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->titleItem('h3'),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_HEADER_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_FOOTER_LOCAL_ID),
      ],
    ]);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], [
      'title' => ['#plain_text' => 'THE_TITLE'],
      'header' => ['#markup' => 'THE_HEADER'],
      'footer' => ['#markup' => 'THE_FOOTER'],
    ]);

    self::assertStringContainsString('<h3>THE_TITLE</h3>', $rendered);
    $expected = ['THE_TITLE', 'THE_HEADER', 'THE_ROWS', 'THE_FOOTER'];
    self::assertSame($expected, $this->orderOf($rendered, $expected));
    self::assertSame(1, \substr_count($rendered, 'THE_HEADER'));
    self::assertStringNotContainsString('<header', $rendered);
    self::assertStringNotContainsString('<footer', $rendered);
  }

  /**
   * Header, footer and other areas show only where placed.
   */
  public function testHeaderAndFooterShowOnlyWherePlaced(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
    ]);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], [
      'header' => ['#markup' => 'THE_HEADER'],
      'attachment_before' => ['#markup' => 'ATTACH_BEFORE'],
      'attachment_after' => ['#markup' => 'ATTACH_AFTER'],
      'more' => ['#markup' => 'THE_MORE'],
      'footer' => ['#markup' => 'THE_FOOTER'],
      'feed_icons' => ['#markup' => 'THE_FEED'],
      'title' => ['#plain_text' => 'THE_TITLE'],
    ]);

    self::assertStringNotContainsString('THE_HEADER', $rendered);
    self::assertStringNotContainsString('THE_FOOTER', $rendered);
    self::assertStringNotContainsString('THE_TITLE', $rendered);
    self::assertStringNotContainsString('THE_MORE', $rendered);
    self::assertStringNotContainsString('ATTACH_BEFORE', $rendered);
    self::assertStringNotContainsString('ATTACH_AFTER', $rendered);
    self::assertStringNotContainsString('THE_FEED', $rendered);
    self::assertStringContainsString('THE_ROWS', $rendered);
  }

  /**
   * The feed icons show where "View feed icons" is placed.
   */
  public function testFeedIconsShowWherePlaced(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->feedIconsItem(ViewTemplateFeedIcons::LABEL_VISIBLE),
      ],
    ]);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], [
      'feed_icons' => ['#markup' => 'THE_FEED'],
    ]);
    $order = ['THE_ROWS', 'THE_FEED'];
    self::assertSame($order, $this->orderOf($rendered, $order));
    self::assertSame(1, \substr_count($rendered, 'THE_FEED'));
    self::assertStringNotContainsString('label-hidden', $rendered);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], []);
    self::assertStringNotContainsString('cvt-feed-icons', $rendered);
  }

  /**
   * The "Label" setting hides the feed's title visually, keeping the markup.
   */
  public function testFeedIconsLabelCanBeHidden(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->feedIconsItem(ViewTemplateFeedIcons::LABEL_HIDDEN)],
    ]);

    $rendered = $this->renderWithAreas($view_template, '', [
      'feed_icons' => ['#markup' => 'THE_FEED'],
    ]);
    self::assertStringContainsString('cvt-feed-icons--label-hidden', $rendered);
    self::assertStringContainsString('THE_FEED', $rendered);
  }

  /**
   * The attachments show where "View attachments" is placed, before first.
   */
  public function testAttachmentsShowWherePlaced(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ATTACHMENTS_LOCAL_ID),
      ],
    ]);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], [
      'attachment_after' => ['#markup' => 'ATTACH_AFTER'],
      'attachment_before' => ['#markup' => 'ATTACH_BEFORE'],
    ]);
    $order = ['THE_ROWS', 'ATTACH_BEFORE', 'ATTACH_AFTER'];
    self::assertSame($order, $this->orderOf($rendered, $order));
    self::assertSame(1, \substr_count($rendered, 'ATTACH_BEFORE'));

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], []);
    self::assertStringNotContainsString('ATTACH', $rendered);
  }

  /**
   * The "more" link shows where "View more link" is placed, once.
   */
  public function testMoreLinkShowsWherePlaced(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_MORE_LOCAL_ID),
      ],
    ]);

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], [
      'more' => ['#markup' => 'THE_MORE'],
    ]);
    $order = ['THE_ROWS', 'THE_MORE'];
    self::assertSame($order, $this->orderOf($rendered, $order));
    self::assertSame(1, \substr_count($rendered, 'THE_MORE'));

    $rendered = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], []);
    self::assertStringNotContainsString('THE_MORE', $rendered);
  }

  /**
   * The "View rows" component shows the empty text when there are no rows.
   */
  public function testViewRowsShowsEmptyTextWhenThereAreNoRows(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
    ]);

    $empty = ['empty' => ['#markup' => 'NO_RESULTS_TEXT']];
    self::assertStringContainsString('NO_RESULTS_TEXT', $this->renderWithAreas($view_template, '', $empty));
    self::assertStringNotContainsString('NO_RESULTS_TEXT', $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], $empty));
  }

  /**
   * The "View no results" component shows content only without rows.
   */
  public function testViewEmptyShowsDroppedContentOnlyWhenThereAreNoRows(): void {
    [$empty_item, $child_item] = $this->viewEmptyWithChild();
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $empty_item,
        $child_item,
      ],
    ]);
    $areas = ['title' => ['#plain_text' => 'DROPPED_CONTENT'], 'empty' => ['#markup' => 'VIEWS_EMPTY_TEXT']];

    $no_results = $this->renderWithAreas($view_template, '', $areas);
    self::assertSame(1, \substr_count($no_results, 'DROPPED_CONTENT'));
    self::assertStringNotContainsString('VIEWS_EMPTY_TEXT', $no_results);

    $with_results = $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], $areas);
    self::assertStringContainsString('THE_ROWS', $with_results);
    self::assertStringNotContainsString('DROPPED_CONTENT', $with_results);
    self::assertStringNotContainsString('VIEWS_EMPTY_TEXT', $with_results);
  }

  /**
   * A "View no results" with nothing dropped in falls back to the Views text.
   */
  public function testViewEmptyWithNothingInItShowsTheViewsEmptyText(): void {
    [$empty_item] = $this->viewEmptyWithChild();
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $empty_item,
      ],
    ]);
    $areas = ['empty' => ['#markup' => 'VIEWS_EMPTY_TEXT']];

    self::assertSame(1, \substr_count($this->renderWithAreas($view_template, '', $areas), 'VIEWS_EMPTY_TEXT'));
    self::assertStringNotContainsString('VIEWS_EMPTY_TEXT', $this->renderWithAreas($view_template, ['#markup' => 'THE_ROWS'], $areas));
  }

  /**
   * Builds a "View no results" tree item with a "View title" inside it.
   *
   * @return array{0: array, 1: array}
   *   The tree item and its child's.
   */
  private function viewEmptyWithChild(): array {
    $component_id = ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $empty_uuid = \Drupal::service('uuid')->generate();
    $empty_item = [
      'uuid' => $empty_uuid,
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => [],
    ];
    $child_item = [
      'parent_uuid' => $empty_uuid,
      'slot' => ViewTemplateEmpty::SLOT_NAME,
    ] + $this->titleItem('none');
    return [$empty_item, $child_item];
  }

  /**
   * The "View title" component outputs the title at its set level.
   */
  public function testViewTitleElement(): void {
    $areas = ['title' => ['#plain_text' => 'THE_TITLE']];
    foreach (['h1', 'h2', 'h4', 'h6'] as $level) {
      $view_template = ViewTemplate::create([
        'id' => 'test',
        'label' => 'Test',
        'component_tree' => [$this->titleItem($level)],
      ]);
      self::assertStringContainsString("<$level>THE_TITLE</$level>", $this->renderWithAreas($view_template, '', $areas), $level);
    }
    $plain = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => [$this->titleItem('none')]]);
    $rendered = $this->renderWithAreas($plain, '', $areas);
    self::assertStringContainsString('THE_TITLE', $rendered);
    self::assertStringNotContainsString('<h', $rendered);
  }

  /**
   * The "View title" component outputs nothing, not an empty heading, if empty.
   */
  public function testViewTitleWithoutTitleOutputsNothing(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->titleItem('h2')],
    ]);

    self::assertStringNotContainsString('<h2', $this->renderWithAreas($view_template, '', []));
  }

  /**
   * Builds a "View title" tree item with the given element setting.
   */
  private function titleItem(string $element): array {
    $component_id = ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      // Stored flat: Canvas wraps these under `settings` when it hydrates.
      'inputs' => [ViewTemplateTitle::INPUT_ELEMENT => $element],
    ];
  }

  /**
   * Builds a "View feed icons" tree item with the given label setting.
   */
  private function feedIconsItem(string $label): array {
    $component_id = ViewTemplateFeedIcons::SOURCE_PLUGIN_ID . '.' . ViewTemplateFeedIcons::LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => [ViewTemplateFeedIcons::INPUT_LABEL => $label],
    ];
  }

  /**
   * Renders a template with the given rows and Views areas.
   */
  private function renderWithAreas(ViewTemplate $view_template, array|string $rows, array $areas): string {
    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, $rows, '', '', [], 0, $areas);
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * Returns the given needles in the order they appear in the markup.
   *
   * @param string $rendered
   *   The rendered markup.
   * @param string[] $needles
   *   The strings to look for.
   *
   * @return string[]
   *   The needles, in order of appearance.
   */
  private function orderOf(string $rendered, array $needles): array {
    $positions = [];
    foreach ($needles as $needle) {
      self::assertStringContainsString($needle, $rendered);
      $positions[$needle] = \strpos($rendered, $needle);
    }
    \asort($positions);
    return \array_keys($positions);
  }

  /**
   * A standalone "View item" (outside any repeater) shows the first row.
   */
  public function testViewItemStandaloneFallsBackToFirstRow(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID),
      ],
    ]);

    $result = $this->container->get(ViewTemplateRenderer::class)->render(
      $view_template,
      '',
      '',
      '',
      ['ROW_A', 'ROW_B', 'ROW_C'],
    );
    $rendered = (string) $this->renderer->renderInIsolation($result);

    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringNotContainsString('ROW_B', $rendered);
    self::assertStringNotContainsString('ROW_C', $rendered);
  }

  /**
   * A repeater with "View item" in its slot repeats once per row.
   */
  public function testRepeaterRepeatsOncePerRow(): void {
    [, $repeater_item, $item_item] = $this->repeaterWithItem();
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$repeater_item, $item_item],
    ]);

    $result = $this->container->get(ViewTemplateRenderer::class)->render(
      $view_template,
      '',
      '',
      '',
      ['ROW_A', 'ROW_B', 'ROW_C'],
    );
    $rendered = (string) $this->renderer->renderInIsolation($result);

    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);
    self::assertStringContainsString('ROW_C', $rendered);
    self::assertSame(1, \substr_count($rendered, 'ROW_A'));
  }

  /**
   * A repeater's own `limit`/`offset` slice which rows it repeats over.
   */
  public function testRepeaterLimitAndOffset(): void {
    [, $repeater_item, $item_item] = $this->repeaterWithItem([
      ViewTemplateRepeater::INPUT_LIMIT => 1,
      ViewTemplateRepeater::INPUT_OFFSET => 1,
    ]);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$repeater_item, $item_item],
    ]);

    $result = $this->container->get(ViewTemplateRenderer::class)->render(
      $view_template,
      '',
      '',
      '',
      ['ROW_A', 'ROW_B', 'ROW_C'],
    );
    $rendered = (string) $this->renderer->renderInIsolation($result);

    self::assertStringNotContainsString('ROW_A', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);
    self::assertStringNotContainsString('ROW_C', $rendered);
  }

  /**
   * Multiple repeaters each apply their own limit/offset to the full row set.
   */
  public function testMultipleIndependentRepeaters(): void {
    [, $featured_repeater, $featured_item] = $this->repeaterWithItem([
      ViewTemplateRepeater::INPUT_LIMIT => 2,
      ViewTemplateRepeater::INPUT_OFFSET => 0,
    ]);
    [, $rest_repeater, $rest_item] = $this->repeaterWithItem([
      ViewTemplateRepeater::INPUT_OFFSET => 2,
    ]);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$featured_repeater, $featured_item, $rest_repeater, $rest_item],
    ]);

    $result = $this->container->get(ViewTemplateRenderer::class)->render(
      $view_template,
      '',
      '',
      '',
      ['ROW_A', 'ROW_B', 'ROW_C', 'ROW_D', 'ROW_E'],
    );
    $rendered = (string) $this->renderer->renderInIsolation($result);

    foreach (['ROW_A', 'ROW_B', 'ROW_C', 'ROW_D', 'ROW_E'] as $row) {
      self::assertSame(1, \substr_count($rendered, $row), "$row appears exactly once");
    }
  }

  /**
   * A repeater's page condition gates it by pager page.
   */
  public function testPageCondition(): void {
    [, $featured_repeater, $featured_item] = $this->repeaterWithItem([
      ViewTemplateRepeater::INPUT_LIMIT => 1,
      ViewTemplateRepeater::INPUT_OFFSET => 0,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ONLY,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => 1,
    ]);
    [, $rest_repeater, $rest_item] = $this->repeaterWithItem([
      ViewTemplateRepeater::INPUT_OFFSET => 1,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_EXCEPT,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => 1,
    ]);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$featured_repeater, $featured_item, $rest_repeater, $rest_item],
    ]);
    $rows = ['ROW_A', 'ROW_B', 'ROW_C'];

    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', '', $rows, 0);
    $rendered = (string) $this->renderer->renderInIsolation($result);
    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringNotContainsString('ROW_B', $rendered);
    self::assertStringNotContainsString('ROW_C', $rendered);

    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', '', $rows, 1);
    $rendered = (string) $this->renderer->renderInIsolation($result);
    self::assertStringNotContainsString('ROW_A', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);
    self::assertStringContainsString('ROW_C', $rendered);

    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', '', $rows, 2);
    $rendered = (string) $this->renderer->renderInIsolation($result);
    self::assertStringNotContainsString('ROW_A', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);
    self::assertStringContainsString('ROW_C', $rendered);
  }

  /**
   * Builds a root-level placeholder tree item.
   */
  private function placeholderItem(string $local_id): array {
    $component_id = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . $local_id;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => [],
    ];
  }

  /**
   * Builds a "View repeater" tree item with a "View item" in its slot.
   *
   * @param array $repeater_inputs
   *   Explicit input for the repeater.
   *
   * @return array{0: string, 1: array, 2: array}
   *   The repeater's UUID, its tree item, and its child's.
   */
  private function repeaterWithItem(array $repeater_inputs = []): array {
    $repeater_component_id = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;
    $repeater_component = Component::load($repeater_component_id);
    self::assertNotNull($repeater_component);
    $repeater_uuid = \Drupal::service('uuid')->generate();
    $repeater_item = [
      'uuid' => $repeater_uuid,
      'component_id' => $repeater_component_id,
      'component_version' => $repeater_component->getActiveVersion(),
      'inputs' => $repeater_inputs + [ViewTemplateRepeater::INPUT_OFFSET => 0],
    ];

    $item_component_id = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID;
    $item_component = Component::load($item_component_id);
    self::assertNotNull($item_component);
    $item_item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'parent_uuid' => $repeater_uuid,
      'slot' => ViewTemplateRepeater::SLOT_NAME,
      'component_id' => $item_component_id,
      'component_version' => $item_component->getActiveVersion(),
      'inputs' => [],
    ];

    return [$repeater_uuid, $repeater_item, $item_item];
  }

}
