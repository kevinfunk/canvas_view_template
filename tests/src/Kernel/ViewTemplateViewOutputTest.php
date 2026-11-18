<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a whole View rendered through its view template and Twig template.
 *
 * @see \Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks
 * @see templates/views-view--canvas-view-template.html.twig
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[Group('canvas_view_template')]
final class ViewTemplateViewOutputTest extends ViewTemplateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
  }

  /**
   * Renders a View that has no results, through a template with only rows.
   *
   * The View has a header, a footer and "no results" text configured in the
   * Views UI. The template places none of them, so only the "no results" text
   * (which "View rows" shows in place of the rows) appears.
   */
  public function testViewWithNoResultsKeepsWrapperAndEmptyText(): void {
    $rows_component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID);
    self::assertNotNull($rows_component);
    ViewTemplate::create([
      'id' => 'output_test',
      'label' => 'Output test',
      'component_tree' => [[
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'component_id' => $rows_component->id(),
        'component_version' => $rows_component->getActiveVersion(),
        'inputs' => [],
      ],
      ],
    ])->save();

    $area = fn (string $id, string $content) => [
      $id => [
        'id' => $id,
        'table' => 'views',
        'field' => 'area_text_custom',
        'plugin_id' => 'text_custom',
        'empty' => TRUE,
        'content' => $content,
      ],
    ];
    View::create([
      'id' => 'output_test_view',
      'label' => 'Output test view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'output_test'],
            ],
            'row' => ['type' => 'fields'],
            'header' => $area('header_text', 'THE_HEADER'),
            'footer' => $area('footer_text', 'THE_FOOTER'),
            'empty' => $area('empty_text', 'NO_RESULTS_TEXT'),
          ],
        ],
      ],
    ])->save();

    $view = Views::getView('output_test_view');
    self::assertNotNull($view);
    $build = $view->buildRenderable('default');
    $html = (string) $this->renderer->renderInIsolation($build);

    // The wrapper Views' AJAX needs, from core's own template.
    self::assertMatchesRegularExpression('/<div[^>]*class="[^"]*js-view-dom-id-[^"]*"/', $html);
    // The template places only "View rows", so the header and footer the View
    // has are not shown, but its "no results" text is, in place of the rows.
    self::assertStringContainsString('NO_RESULTS_TEXT', $html);
    self::assertStringNotContainsString('THE_HEADER', $html);
    self::assertStringNotContainsString('THE_FOOTER', $html);
  }

}
