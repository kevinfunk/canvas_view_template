<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Render\ViewRenderDataCollector;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the collector that turns an executed View into render data.
 */
#[CoversClass(ViewRenderDataCollector::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewRenderDataCollectorTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  private const string ITEM_ID = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    // Not uid 1, which would have every permission.
    $this->setUpCurrentUser(['uid' => 2], ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    foreach (['One', 'Two', 'Three'] as $title) {
      Node::create(['type' => 'article', 'title' => $title, 'status' => 1])->save();
    }
    ViewTemplate::create(['id' => 'collector_test', 'label' => 'Collector test'])->save();
    View::create([
      'id' => 'collector_view',
      'label' => 'Collector view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'title' => 'The articles',
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'collector_test'],
            ],
            'row' => ['type' => 'fields'],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 2]],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'order' => 'ASC',
                'entity_type' => 'node',
                'entity_field' => 'nid',
                'plugin_id' => 'standard',
              ],
            ],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'label' => 'Headline',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Executes and renders the View, as the front end has by collection time.
   */
  private function executedView(int $page = 0): ViewExecutable {
    $view = Views::getView('collector_view');
    self::assertNotNull($view);
    $view->setDisplay('default');
    $view->setCurrentPage($page);
    $view->execute();
    $this->renderer->executeInRenderContext(new RenderContext(), static fn () => $view->render());
    return $view;
  }

  /**
   * Without a list of used components, everything is collected.
   */
  public function testNullCollectsEverything(): void {
    $data = ViewRenderDataCollector::collect($this->executedView(), [], '#', NULL, new CacheableMetadata());

    self::assertCount(2, $data->rowRenders);
    self::assertSame(0, $data->currentPage);
    self::assertSame(['title' => 'Headline'], $data->fieldData['labels']);
    self::assertSame(['One', 'Two'], \array_map(static fn (array $row): string => \strip_tags((string) $row['title']['#markup']), $data->fieldData['rows']));
    self::assertSame(['/node/1', '/node/2'], $data->itemUrls);
    self::assertSame('1', $data->summary['@start']);
    self::assertSame('2', $data->summary['@end']);
    self::assertSame('3', $data->summary['@total']);
    self::assertSame('2', $data->summary['@page_count']);
    self::assertSame(
      ['has_results' => TRUE, 'multiple_pages' => TRUE, 'first_page' => TRUE, 'filters_applied' => FALSE],
      $data->facts,
    );
  }

  /**
   * A template that places nothing gets none of the optional data.
   */
  public function testUnusedDataIsSkipped(): void {
    $cacheability = new CacheableMetadata();
    $data = ViewRenderDataCollector::collect($this->executedView(), [], '#', [], $cacheability);

    self::assertSame([], $data->rowRenders);
    self::assertSame([], $data->fieldData);
    self::assertSame([], $data->summary);
    self::assertSame([], $data->facts);
    self::assertSame([], $data->itemUrls);
    self::assertSame([], $cacheability->getCacheTags(), 'Skipped data adds no cacheability.');
  }

  /**
   * Each piece is collected only when its own component is placed.
   */
  public function testEachPieceNeedsItsOwnComponent(): void {
    $pieces = ['rowRenders', 'fieldData', 'summary', 'facts', 'itemUrls'];
    $cases = [
      ViewTemplateRenderer::VIEW_REPEATER_COMPONENT_ID => 'rowRenders',
      self::ITEM_ID => 'rowRenders',
      ViewTemplateRenderer::VIEW_FIELD_COMPONENT_ID => 'fieldData',
      ViewTemplateRenderer::VIEW_SUMMARY_COMPONENT_ID => 'summary',
      ViewTemplateRenderer::VIEW_CONDITION_COMPONENT_ID => 'facts',
      ViewTemplateRenderer::VIEW_ITEM_LINK_COMPONENT_ID => 'itemUrls',
    ];
    $view = $this->executedView();
    foreach ($cases as $component_id => $expected) {
      $data = ViewRenderDataCollector::collect($view, [], '#', [$component_id], new CacheableMetadata());
      foreach ($pieces as $piece) {
        if ($piece === $expected) {
          self::assertNotSame([], $data->$piece, "$component_id should collect $piece.");
        }
        else {
          self::assertSame([], $data->$piece, "$component_id should not collect $piece.");
        }
      }
    }
  }

  /**
   * Render array keys (`#rows`) and theme variable keys (`rows`) both work.
   */
  public function testKeyPrefixVariants(): void {
    $view = $this->executedView();
    $pieces = [
      'rows' => ['#markup' => 'ROWS'],
      'exposed' => ['#markup' => 'EXPOSED'],
      'pager' => ['#markup' => 'PAGER'],
      'header' => ['#markup' => 'HEADER'],
      'footer' => ['#markup' => 'FOOTER'],
      'empty' => [],
      'more' => ['#markup' => 'MORE'],
    ];
    foreach (['#', ''] as $prefix) {
      $element = [];
      foreach ($pieces as $key => $value) {
        $element[$prefix . $key] = $value;
      }
      $data = ViewRenderDataCollector::collect($view, $element, $prefix, [], new CacheableMetadata());
      self::assertSame(['#markup' => 'ROWS'], $data->rows, "prefix '$prefix'");
      self::assertSame(['#markup' => 'EXPOSED'], $data->exposed);
      self::assertSame(['#markup' => 'PAGER'], $data->pager);
      self::assertSame(['#markup' => 'HEADER'], $data->areas['header']);
      self::assertSame(['#markup' => 'FOOTER'], $data->areas['footer']);
      self::assertSame(['#markup' => 'MORE'], $data->areas['more']);
      self::assertArrayNotHasKey('empty', $data->areas, 'An empty area is left out.');
      self::assertSame(['#plain_text' => 'The articles'], $data->areas['title'], 'The title comes from the View, not the element.');
    }

    // The wrong prefix finds nothing.
    $data = ViewRenderDataCollector::collect($view, ['#rows' => ['#markup' => 'ROWS']], '', [], new CacheableMetadata());
    self::assertSame([], $data->rows);
  }

  /**
   * The pager's page decides which rows, links and numbers are collected.
   */
  public function testSecondPage(): void {
    $data = ViewRenderDataCollector::collect($this->executedView(1), [], '#', NULL, new CacheableMetadata());

    self::assertSame(1, $data->currentPage);
    self::assertCount(1, $data->rowRenders);
    self::assertSame(['/node/3'], $data->itemUrls);
    self::assertSame('3', $data->summary['@start']);
    self::assertSame('3', $data->summary['@end']);
    self::assertFalse($data->facts['first_page']);
    self::assertTrue($data->facts['multiple_pages']);
  }

  /**
   * What the item URLs depend on is carried into the caller's cacheability.
   */
  public function testCacheabilityIsCarried(): void {
    $cacheability = new CacheableMetadata();
    ViewRenderDataCollector::collect($this->executedView(), [], '#', [ViewTemplateRenderer::VIEW_ITEM_LINK_COMPONENT_ID], $cacheability);

    self::assertContains('node:1', $cacheability->getCacheTags());
    self::assertContains('node:2', $cacheability->getCacheTags());
    self::assertNotContains('node:3', $cacheability->getCacheTags(), 'Only the shown page.');
  }

  /**
   * An item the user may not view gets no link, and the cacheability says why.
   */
  public function testInaccessibleItemHasNoUrl(): void {
    $this->setUpCurrentUser(['uid' => 3], []);
    $cacheability = new CacheableMetadata();
    $data = ViewRenderDataCollector::collect($this->executedView(), [], '#', [ViewTemplateRenderer::VIEW_ITEM_LINK_COMPONENT_ID], $cacheability);

    self::assertSame([NULL, NULL], $data->itemUrls);
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
  }

}
