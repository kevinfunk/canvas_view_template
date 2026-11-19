<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;

/**
 * Base class for this module's kernel tests.
 *
 * Installs `canvas_dev_mode` because Canvas's `canvas.component.*` schema
 * restricts `source` plugin IDs, and strict schema checking would otherwise
 * reject this module's install config. Dev mode lifts that restriction.
 */
abstract class ViewTemplateKernelTestBase extends CanvasKernelTestBase {

  // Re-declared because a private trait method is only callable from the class
  // that uses the trait; aliased to protected for subclasses.
  use ConstraintViolationsTestTrait {
    violationsToArray as protected;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
  ];

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_view_template']);
    $this->renderer = $this->container->get(RendererInterface::class);
  }

  /**
   * Renders a template with the given View data.
   */
  protected function renderWithData(ViewTemplate $template, ViewRenderData $data = new ViewRenderData()): array {
    return $this->container->get(ViewTemplateRenderer::class)->render($template, $data);
  }

  /**
   * Installs what the tests that render nodes need, with Stark as the theme.
   */
  protected function installNodeSupport(): void {
    $this->installEntitySchema('node');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
  }

  /**
   * Builds a tree item for a component.
   *
   * A root-level item omits `parent_uuid` and `slot`. Inputs default to the
   * component's default explicit input.
   */
  protected function treeItem(string $component_id, ?string $parent_uuid = NULL, ?string $slot = NULL, ?array $inputs = NULL, ?string $uuid = NULL): array {
    $component = Component::load($component_id);
    self::assertNotNull($component, $component_id);
    $item = [
      'uuid' => $uuid ?? $this->container->get('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $inputs ?? $component->getComponentSource()->getDefaultExplicitInput(),
    ];
    if ($parent_uuid !== NULL) {
      $item['parent_uuid'] = $parent_uuid;
      $item['slot'] = $slot;
    }
    return $item;
  }

  /**
   * Builds a root-level tree item for a placeholder component.
   */
  protected function placeholderItem(string $local_id): array {
    return $this->treeItem(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . $local_id);
  }

  /**
   * Creates a valid `PageVariant`, which needs one "Page content" marker.
   */
  protected function createPageVariant(string $id, string $label): PageVariant {
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
          'uuid' => $this->container->get('uuid')->generate(),
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
