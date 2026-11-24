<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\EventSubscriber\ViewTemplatePageVariantSelectorSubscriber;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests the per-View "Page template" override.
 *
 * Dispatches the real event so Canvas's own subscriber (which runs first)
 * and this module's service wiring are exercised too.
 */
#[CoversClass(ViewTemplatePageVariantSelectorSubscriber::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplatePageVariantSelectorSubscriberTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

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
  }

  /**
   * No style override defers to the site-wide default page variant.
   */
  public function testNoOverrideDefersToSiteDefault(): void {
    $this->createPageVariant('site_default', 'Site default');
    $this->config('canvas.settings')->set('default_page_variant', 'site_default')->save();

    $this->createStyledView('no_override_view', NULL);
    $event = $this->dispatchFor('no_override_view');

    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());
    self::assertSame('site_default', $event->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY]);
  }

  /**
   * The "no page template" option forces core's block layout.
   */
  public function testNoPageTemplateForcesBlockPage(): void {
    $this->createStyledView('none_view', ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE);
    $event = $this->dispatchFor('none_view');

    self::assertSame('block_page', $event->getPluginId());
  }

  /**
   * A real `PageVariant` id selects Canvas's page-variant plugin.
   */
  public function testExplicitPageVariantOverride(): void {
    $this->createPageVariant('full_override', 'Full override');
    $this->createStyledView('override_view', 'full_override');
    $event = $this->dispatchFor('override_view');

    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());
    self::assertSame('full_override', $event->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY]);
  }

  /**
   * A reference to a deleted PageVariant is ignored.
   */
  public function testStalePageVariantReferenceIsIgnored(): void {
    $this->createStyledView('stale_view', 'does_not_exist');
    $event = $this->dispatchFor('stale_view');

    self::assertSame('block_page', $event->getPluginId());
  }

  /**
   * The editor's preview uses the page template of the previewed display.
   */
  public function testEditorPreviewUsesChosenDisplaysPageTemplate(): void {
    // The choice is remembered per user.
    $this->setUpCurrentUser(['uid' => 2], ['access content']);
    $this->createPageVariant('full_override', 'Full override');
    $this->createStyledView('override_view', 'full_override');
    $template = ViewTemplate::create(['id' => 'preview_pt', 'label' => 'Preview']);
    $template->save();
    $route = new Route('/canvas/api/v0/layout/canvas_view_template/{entity}');
    $route_match = new RouteMatch('canvas.api.layout.get', $route, ['entity' => $template], ['entity' => 'preview_pt']);
    $dispatch = function () use ($route_match): PageDisplayVariantSelectionEvent {
      $event = new PageDisplayVariantSelectionEvent('initial_plugin_id', $route_match);
      $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
      return $event;
    };

    // No View chosen: the site's usual page template.
    self::assertNotSame('full_override', $dispatch()->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY] ?? NULL);

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_pt', 'override_view/page_1');
    $event = $dispatch();
    self::assertSame(CanvasPageVariant::PLUGIN_ID, $event->getPluginId());
    self::assertSame('full_override', $event->getPluginConfiguration()[CanvasPageVariant::VARIANT_ID_KEY]);
  }

  /**
   * Dispatches a page-display-variant selection event for the given view.
   */
  private function dispatchFor(string $view_id): PageDisplayVariantSelectionEvent {
    $route = new Route('/' . $view_id, [
      'view_id' => $view_id,
      'display_id' => 'page_1',
    ]);
    $route_match = new RouteMatch('view.' . $view_id . '.page_1', $route);
    $event = new PageDisplayVariantSelectionEvent('initial_plugin_id', $route_match);
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event;
  }

  /**
   * Creates a View with a `page_1` display using the template style.
   */
  private function createStyledView(string $id, ?string $page_variant_option): void {
    $style_options = [];
    if ($page_variant_option !== NULL) {
      $style_options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY] = $page_variant_option;
    }
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'row' => ['type' => 'fields'],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_options' => [
            'path' => $id,
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => $style_options,
            ],
            'defaults' => ['style' => FALSE],
          ],
        ],
      ],
    ])->save();
  }

}
