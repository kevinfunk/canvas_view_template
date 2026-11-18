<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Form\FormState;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the "Canvas view template" Views style plugin.
 */
#[CoversClass(ViewTemplateStyle::class)]
#[Group('canvas_view_template')]
final class ViewTemplateStyleTest extends ViewTemplateKernelTestBase {

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
   * The style plugin under test.
   */
  private ViewTemplateStyle $style;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');

    View::create([
      'id' => 'style_test_view',
      'label' => 'Style test view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => ['type' => ViewTemplateStyle::PLUGIN_ID],
            'row' => ['type' => 'fields'],
          ],
        ],
      ],
    ])->save();

    $view = Views::getView('style_test_view');
    self::assertNotNull($view);
    self::assertTrue($view->setDisplay('default'));
    $style = $view->display_handler->getPlugin('style');
    self::assertInstanceOf(ViewTemplateStyle::class, $style);
    $this->style = $style;
  }

  /**
   * The "View template" and "Page template" selects list existing entities.
   */
  public function testBuildOptionsFormReflectsRealEntities(): void {
    ViewTemplate::create(['id' => 'vt_one', 'label' => 'VT One'])->save();
    $this->createPageVariant('pv_one', 'PV One');

    $form = [];
    $form_state = new FormState();
    $this->style->buildOptionsForm($form, $form_state);

    self::assertArrayHasKey(ViewTemplateStyle::OPTION_KEY, $form);
    self::assertArrayHasKey('vt_one', $form[ViewTemplateStyle::OPTION_KEY]['#options']);

    self::assertArrayHasKey(ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY, $form);
    $page_variant_options = $form[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY]['#options'];
    self::assertArrayHasKey('pv_one', $page_variant_options);
    // The "site default" and "no page template" options are always present.
    self::assertArrayHasKey('', $page_variant_options);
    self::assertArrayHasKey(ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE, $page_variant_options);
  }

  /**
   * Submitting an empty selection stores NULL (unset).
   */
  public function testSubmitOptionsFormStoresNullForUnset(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      ViewTemplateStyle::OPTION_KEY => '',
      ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY => '',
    ]);
    $this->style->submitOptionsForm($form, $form_state);

    self::assertNull($this->style->options[ViewTemplateStyle::OPTION_KEY]);
    self::assertNull($this->style->options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY]);
  }

  /**
   * Submitting real selections stores them verbatim.
   */
  public function testSubmitOptionsFormStoresSelections(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      ViewTemplateStyle::OPTION_KEY => 'vt_one',
      ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY => ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE,
    ]);
    $this->style->submitOptionsForm($form, $form_state);

    self::assertSame('vt_one', $this->style->options[ViewTemplateStyle::OPTION_KEY]);
    self::assertSame(ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE, $this->style->options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY]);
  }

  /**
   * The helper links point at Canvas, and the summary names the template.
   */
  public function testHelperLinksAndSummaryTitle(): void {
    ViewTemplate::create(['id' => 'vt_link', 'label' => 'VT Link'])->save();
    ViewTemplate::create(['id' => 'vt_other', 'label' => 'VT Other'])->save();

    // Nothing saved yet: the edit link is rendered but hidden, for the JS.
    $form = [];
    $this->style->buildOptionsForm($form, new FormState());
    $links = $form['template_links'];
    self::assertTrue($links['edit']['#attributes']['hidden']);
    self::assertSame('canvas.boot.app', $links['create']['#url']->getRouteName());
    self::assertSame(['extension_id' => 'view_templates'], $links['create']['#url']->getRouteParameters());
    // The new-tab hint is inside the link, with no trailing whitespace.
    self::assertStringEndsWith('</span>', (string) $links['create']['#title']);
    self::assertStringContainsString('opens in a new tab', (string) $links['create']['#title']);

    // The JS gets an edit URL for every template, to follow the select.
    $edit_urls = $form['#attached']['drupalSettings']['canvasViewTemplate']['editUrls'];
    self::assertSame('/canvas/editor/view_template/vt_other', $edit_urls['vt_other']);
    self::assertContains('canvas_view_template/style_options', $form['#attached']['library']);
    self::assertArrayHasKey('data-cvt-template-select', $form[ViewTemplateStyle::OPTION_KEY]['#attributes']);

    // With a saved template, the edit link is shown and points at it.
    $this->style->options[ViewTemplateStyle::OPTION_KEY] = 'vt_link';
    $form = [];
    $this->style->buildOptionsForm($form, new FormState());
    $edit = $form['template_links']['edit'];
    self::assertFalse($edit['#attributes']['hidden']);
    self::assertSame('canvas.boot.entity', $edit['link']['#url']->getRouteName());
    self::assertSame(['entity_type' => 'view_template', 'entity' => 'vt_link'], $edit['link']['#url']->getRouteParameters());
    self::assertSame('VT Link', (string) $this->style->summaryTitle());
  }

  /**
   * Selected templates are not config dependencies.
   *
   * Otherwise deleting a template would delete the whole View.
   */
  public function testTemplatesAreNotConfigDependencies(): void {
    $view_template = ViewTemplate::create(['id' => 'vt_dep', 'label' => 'VT Dep']);
    $view_template->save();
    $page_variant = $this->createPageVariant('pv_dep', 'PV Dep');

    $this->style->options[ViewTemplateStyle::OPTION_KEY] = 'vt_dep';
    $this->style->options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY] = 'pv_dep';
    $dependencies = $this->style->calculateDependencies();

    self::assertNotContains($view_template->getConfigDependencyName(), $dependencies[$view_template->getConfigDependencyKey()] ?? []);
    self::assertNotContains($page_variant->getConfigDependencyName(), $dependencies[$page_variant->getConfigDependencyKey()] ?? []);
  }

  /**
   * The "no page template" sentinel is not recorded as a dependency.
   */
  public function testCalculateDependenciesOmitsNoPageTemplateSentinel(): void {
    $this->style->options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY] = ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE;
    $dependencies = $this->style->calculateDependencies();

    foreach ($dependencies as $items) {
      self::assertNotContains('canvas.page_variant.' . ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE, $items);
    }
  }

  /**
   * Creates a valid `PageVariant`, which needs one "Page content" marker.
   */
  private function createPageVariant(string $id, string $label): PageVariant {
    // Mirrors config/install/canvas.component.marker.page_content.yml.
    $marker_version = '3b12c0b99a6caecc';
    if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
      Component::create([
        'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'label' => 'Page content',
        'provider' => 'canvas',
        'source' => Marker::SOURCE_PLUGIN_ID,
        'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
        'active_version' => $marker_version,
        'versioned_properties' => [
          'active' => [
            'settings' => [],
            'fallback_metadata' => ['slot_definitions' => []],
          ],
        ],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }
    $page_variant = PageVariant::create([
      'id' => $id,
      'label' => $label,
      'component_tree' => [
        [
          'uuid' => \Drupal::service('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker_version,
          'inputs' => [],
        ],
      ],
    ]);
    $page_variant->save();
    return $page_variant;
  }

}
