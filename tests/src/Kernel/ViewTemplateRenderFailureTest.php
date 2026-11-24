<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a visitor gets when a template cannot be fully rendered.
 *
 * Other failures are not caught on purpose: an exception from a Views handler
 * or from this module's own code reaches Drupal's error handling and the
 * log, as it would for a View without a template. Hiding it would hide the bug.
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateRenderFailureTest extends ViewTemplateKernelTestBase {

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
  }

  /**
   * A placed component whose config is gone is left out; the rest renders.
   *
   * Happens when Canvas or a module that provided the component is removed
   * while templates still place it.
   */
  public function testMissingComponentIsLeftOutAndTheRestRenders(): void {
    $title = $this->treeItem(ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID);
    $rows = $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID);
    ViewTemplate::create(['id' => 'broken', 'label' => 'Broken', 'component_tree' => [$title, $rows]])->save();
    View::create([
      'id' => 'broken_view',
      'label' => 'Broken view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'title' => 'THE_VIEW_TITLE',
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'broken'],
            ],
            'row' => ['type' => 'fields'],
            'empty' => [
              'text' => [
                'id' => 'text',
                'table' => 'views',
                'field' => 'area_text_custom',
                'plugin_id' => 'text_custom',
                'empty' => TRUE,
                'content' => 'NO_RESULTS_TEXT',
              ],
            ],
          ],
        ],
      ],
    ])->save();

    // With every component present, both parts show.
    $html = $this->renderBrokenView();
    self::assertStringContainsString('THE_VIEW_TITLE', $html);
    self::assertStringContainsString('NO_RESULTS_TEXT', $html);

    Component::load(ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID)?->delete();

    // Without the title's component, it is gone and nothing else is.
    $html = $this->renderBrokenView();
    self::assertStringNotContainsString('THE_VIEW_TITLE', $html);
    self::assertStringContainsString('NO_RESULTS_TEXT', $html);
    self::assertMatchesRegularExpression('/class="[^"]*js-view-dom-id-/', $html, 'The View keeps its wrapper.');
  }

  /**
   * Renders the "broken" View's default display.
   */
  private function renderBrokenView(): string {
    $view = Views::getView('broken_view');
    self::assertNotNull($view);
    $build = $view->buildRenderable('default');
    return (string) $this->renderer->renderInIsolation($build);
  }

}
