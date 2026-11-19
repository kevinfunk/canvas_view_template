<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins how a repeater's cost grows with the number of rows.
 *
 * Each row renders the slot's subtree again, and Canvas loads every
 * placed Component for each of those passes. That is linear in the rows by
 * design; this catches anything that makes it worse (a nested repeat, a
 * second pass per row).
 */
#[CoversClass(ViewTemplateRenderer::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRepeaterCostTest extends ViewTemplateKernelTestBase {

  /**
   * Instances placed in the repeater: "View item", a link and six fields.
   */
  private const int INSTANCES = 8;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('config.factory')->setClass(CountingConfigFactory::class);
  }

  /**
   * Counts the Component configs read while rendering the given rows.
   */
  private function componentReadsFor(int $rows): int {
    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $link = $this->treeItem(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $tree = [
      $repeater,
      $this->treeItem(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME),
      $link,
    ];
    $field_inputs = [
      ViewTemplateField::INPUT_FIELD => 'title',
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    for ($i = 0; $i < 6; $i++) {
      $tree[] = $this->treeItem(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, $field_inputs);
    }
    self::assertCount(self::INSTANCES + 1, $tree);
    $template = ViewTemplate::create(['id' => 'cost' . $rows, 'label' => 'Cost', 'component_tree' => $tree]);
    $row_renders = [];
    $field_rows = [];
    for ($i = 0; $i < $rows; $i++) {
      $row_renders[] = ['#markup' => 'ROW_' . $i];
      $field_rows[] = ['title' => ['#markup' => 'TITLE_' . $i]];
    }
    $data = new ViewRenderData(
      rowRenders: $row_renders,
      fieldData: ['labels' => ['title' => 'Title'], 'rows' => $field_rows],
      itemUrls: \array_fill(0, $rows, '/item'),
    );

    CountingConfigFactory::$componentReads = 0;
    $result = $this->renderWithData($template, $data);
    $reads = CountingConfigFactory::$componentReads;
    $rendered = (string) $this->renderer->renderInIsolation($result);
    // The render still shows every row.
    self::assertStringContainsString('ROW_' . ($rows - 1), $rendered);
    self::assertStringContainsString('TITLE_' . ($rows - 1), $rendered);
    return $reads;
  }

  /**
   * Component reads grow by the same amount per row, however many rows.
   */
  public function testComponentReadsGrowLinearlyWithRows(): void {
    $ten = $this->componentReadsFor(10);
    $twenty = $this->componentReadsFor(20);
    $forty = $this->componentReadsFor(40);

    $per_row_first = ($twenty - $ten) / 10;
    $per_row_second = ($forty - $twenty) / 20;
    self::assertSame($per_row_first, $per_row_second, 'Reads per extra row changed with the number of rows.');
    // Loose cap: one read per placed instance, plus the tree's distinct
    // Components, with headroom. A second pass per row would double it.
    self::assertLessThanOrEqual(2 * self::INSTANCES, $per_row_first);
  }

}
