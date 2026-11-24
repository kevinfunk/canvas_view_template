<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a template adds to the cost of rendering a real View.
 *
 * Each View is rendered without caching, with and without a template, over the
 * same nodes. Only ratios are checked, so a slow machine does not fail them:
 * both sides slow down together. Measured: a template of "View rows" costs the
 * same as plain Views, a repeater about 2.5 times, and cost grows with the
 * rows, not faster. The budgets leave at least twice that room.
 *
 * Time is a coarse measure: rendering every repeater row two or three times
 * more is within these budgets, because most of the cost is Views itself.
 * ViewTemplateRepeaterCostTest counts the work done per row and catches that.
 * This test catches the whole path getting much slower, or cost growing faster
 * than the rows.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
#[Group('canvas_view_template_slow')]
final class ViewTemplateRenderCostTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  private const int ROWS = 300;

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
    $this->installEntitySchema('user');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    // Not uid 1, who passes every access check.
    $this->setCurrentUser($this->createUser(['access content'], NULL, FALSE, ['uid' => 9]));
    for ($i = 0; $i < self::ROWS; $i++) {
      Node::create(['type' => 'article', 'title' => 'Node ' . $i, 'status' => TRUE])->save();
    }

    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $link = $this->treeItem(
      ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID,
      $repeater['uuid'],
      ViewTemplateRepeater::SLOT_NAME,
    );
    $field_id = ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID;
    $field = static fn (string $name): array => [
      ViewTemplateField::INPUT_FIELD => $name,
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    ViewTemplate::create([
      'id' => 'cost_rows',
      'label' => 'Rows',
      'component_tree' => [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
    ])->save();
    ViewTemplate::create([
      'id' => 'cost_repeater',
      'label' => 'Repeater',
      'component_tree' => [
        $repeater,
        $link,
        $this->treeItem($field_id, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, $field('title')),
        $this->treeItem($field_id, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, $field('nid')),
      ],
    ])->save();

    // Written as raw config: saving Views one by one is slow in a test.
    $storage = $this->container->get('config.storage');
    $views = [
      'plain_300' => [NULL, self::ROWS],
      'rows_300' => ['cost_rows', self::ROWS],
      'repeater_300' => ['cost_repeater', self::ROWS],
      'repeater_100' => ['cost_repeater', 100],
    ];
    foreach ($views as $id => [$template, $rows]) {
      $storage->write('views.view.cost_' . $id, $this->viewConfig('cost_' . $id, $template, $rows));
    }
    $this->container->get('config.factory')->reset();
  }

  /**
   * The config of a View over the nodes.
   *
   * @param string $id
   *   The View ID.
   * @param string|null $template
   *   The view template, or NULL for the plain Views style.
   * @param int $rows
   *   How many rows it shows.
   *
   * @return array<string, mixed>
   *   Config data.
   */
  private function viewConfig(string $id, ?string $template, int $rows): array {
    $style = ['type' => 'default', 'options' => []];
    if ($template !== NULL) {
      $style = ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => $template]];
    }
    $field = static fn (string $name, string $type): array => [
      'id' => $name,
      'table' => 'node_field_data',
      'field' => $name,
      'entity_type' => 'node',
      'entity_field' => $name,
      'plugin_id' => 'field',
      'label' => '',
      'type' => $type,
      'settings' => $type === 'string' ? ['link_to_entity' => FALSE] : [],
    ];
    return View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => $style,
            'row' => ['type' => 'fields'],
            'pager' => ['type' => 'some', 'options' => ['items_per_page' => $rows, 'offset' => 0]],
            'fields' => ['title' => $field('title', 'string'), 'nid' => $field('nid', 'number_integer')],
          ],
        ],
      ],
    ])->toArray();
  }

  /**
   * The median time, in milliseconds, to render a View without caching.
   */
  private function median(string $view_id): float {
    $times = [];
    // The first render warms plugin and class caches, and is not counted.
    for ($run = 0; $run < 6; $run++) {
      $start = \microtime(TRUE);
      $view = Views::getView($view_id);
      self::assertNotNull($view);
      $build = $view->buildRenderable('default', [], FALSE);
      $html = (string) $this->renderer->renderInIsolation($build);
      $elapsed = (\microtime(TRUE) - $start) * 1000;
      if ($run > 0) {
        $times[] = $elapsed;
      }
    }
    // Every row is really rendered: a fast run that shows nothing is no win.
    self::assertStringContainsString('Node 99', $html, $view_id);
    \sort($times);
    return $times[\intdiv(\count($times), 2)];
  }

  /**
   * A template of "View rows" costs the same as plain Views.
   */
  public function testRowsTemplateAddsNothing(): void {
    $plain = $this->median('cost_plain_300');
    $rows = $this->median('cost_rows_300');
    self::assertLessThan(2.0, $rows / $plain, \sprintf('"View rows" took %.0f ms against %.0f ms for plain Views.', $rows, $plain));
  }

  /**
   * A repeater with a link and two fields stays within several times plain.
   */
  public function testRepeaterStaysWithinSeveralTimesPlainViews(): void {
    $plain = $this->median('cost_plain_300');
    $repeater = $this->median('cost_repeater_300');
    self::assertLessThan(5.0, $repeater / $plain, \sprintf('The repeater took %.0f ms against %.0f ms for plain Views.', $repeater, $plain));
  }

  /**
   * Three times the rows costs about three times as much, not more.
   */
  public function testRepeaterCostGrowsWithTheRows(): void {
    $small = $this->median('cost_repeater_100');
    $large = $this->median('cost_repeater_300');
    self::assertLessThan(6.0, $large / $small, \sprintf('300 rows took %.0f ms and 100 rows %.0f ms.', $large, $small));
  }

}
