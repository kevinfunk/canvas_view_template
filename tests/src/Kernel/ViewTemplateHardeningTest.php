<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\EventSubscriber\ViewTemplatePageVariantSelectorSubscriber;
use Drupal\canvas_view_template\EventSubscriber\ViewTemplatePreviewSubscriber;
use Drupal\canvas_view_template\Form\ViewTemplatePreviewForm;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\Render\ViewItemUrls;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\ResultRow;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests rendering, caching and preview edge cases.
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[CoversClass(ViewItemUrls::class)]
#[CoversClass(ViewConditions::class)]
#[CoversClass(ViewTemplatePreviewSelection::class)]
#[CoversClass(ViewTemplatePreviewSubscriber::class)]
#[CoversClass(ViewTemplatePageVariantSelectorSubscriber::class)]
#[Group('canvas_view_template')]
final class ViewTemplateHardeningTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('node');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
    $this->setUpCurrentUser(['uid' => 2], ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * With a mini pager, the summary still shows the total.
   */
  public function testSummaryTotalWithMiniPager(): void {
    for ($i = 1; $i <= 25; $i++) {
      Node::create(['type' => 'article', 'title' => "Article $i", 'status' => 1])->save();
    }
    $this->createTemplate('summary_template', [$this->item(ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID)]);
    $mini = ['type' => 'mini', 'options' => ['items_per_page' => 10, 'offset' => 0]];
    $this->createView('summary_view', 'summary_template', $mini);

    $view = Views::getView('summary_view');
    self::assertNotNull($view);
    $build = $view->buildRenderable('default');
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('Displaying 1 - 10 of 25', $html);

    $view = Views::getView('summary_view');
    self::assertNotNull($view);
    $view->setDisplay('default');
    $view->execute();
    self::assertTrue($view->get_total_rows);
    self::assertSame(25, (int) $view->total_rows);
    // The mini pager's extra row is not shown, and it still has a next page.
    self::assertCount(10, $view->result);
    self::assertTrue($view->pager->hasMoreRecords());
  }

  /**
   * A disabled or missing template's View output still depends on it.
   */
  public function testFallbackCarriesTemplateCacheTag(): void {
    // No results: with rows, the fallback would render the style's own
    // template, which this module's style plugin mis-registers in tests.
    $template = $this->createTemplate('fallback_template', [$this->item(ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID)]);
    $template->disable()->save();
    $this->createView('fallback_view', 'fallback_template');

    [$html, $tags] = $this->renderView('fallback_view');
    self::assertStringNotContainsString('FALLBACK_TITLE', $html);
    self::assertContains('config:canvas_view_template.view_template.fallback_template', $tags);

    $template->enable()->save();
    [$html] = $this->renderView('fallback_view');
    self::assertStringContainsString('FALLBACK_TITLE', $html);

    $template->delete();
    [$html, $tags] = $this->renderView('fallback_view');
    self::assertStringNotContainsString('FALLBACK_TITLE', $html);
    $list_tags = $this->container->get('entity_type.manager')->getDefinition(ViewTemplate::ENTITY_TYPE_ID)->getListCacheTags();
    self::assertNotSame([], $list_tags);
    self::assertEmpty(\array_diff($list_tags, $tags));
  }

  /**
   * A stale page template reference still depends on page templates.
   */
  public function testStalePageTemplateCarriesListCacheTag(): void {
    View::create([
      'id' => 'stale_page_view',
      'label' => 'Stale page view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_options' => []],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_options' => [
            'path' => 'stale-page-view',
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY => 'missing_variant'],
            ],
            'defaults' => ['style' => FALSE],
          ],
        ],
      ],
    ])->save();
    $route = new Route('/stale-page-view', ['view_id' => 'stale_page_view', 'display_id' => 'page_1']);
    $event = new PageDisplayVariantSelectionEvent('initial_plugin_id', new RouteMatch('view.stale_page_view.page_1', $route));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    $list_tags = $this->container->get('entity_type.manager')->getDefinition(PageVariant::ENTITY_TYPE_ID)->getListCacheTags();
    self::assertEmpty(\array_diff($list_tags, $event->getCacheTags()));
  }

  /**
   * Items link only to pages the current user can view.
   */
  public function testItemUrls(): void {
    $published = Node::create(['type' => 'article', 'title' => 'Published', 'status' => 1]);
    $published->save();
    $unpublished = Node::create(['type' => 'article', 'title' => 'Unpublished', 'status' => 0]);
    $unpublished->save();
    // A media item whose canonical route is its edit form.
    $media = Media::create(['bundle' => 'any', 'name' => 'Media']);

    $this->createView('urls_view', NULL);
    $view = Views::getView('urls_view');
    self::assertNotNull($view);
    $view->result = [
      new ResultRow(['_entity' => $published]),
      new ResultRow(['_entity' => $unpublished]),
      new ResultRow(['_entity' => $media]),
      new ResultRow([]),
    ];
    $cacheability = new CacheableMetadata();
    self::assertSame(['/node/' . $published->id(), NULL, NULL, NULL], ViewItemUrls::collect($view, $cacheability));
    self::assertContains('node:' . $published->id(), $cacheability->getCacheTags());
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
  }

  /**
   * A grouped exposed filter counts as applied under its group identifier.
   */
  public function testGroupedFilterIsApplied(): void {
    View::create([
      'id' => 'grouped_view',
      'label' => 'Grouped view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'row' => ['type' => 'fields'],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'boolean',
                'operator' => '=',
                'value' => '1',
                'exposed' => TRUE,
                'is_grouped' => TRUE,
                'expose' => ['identifier' => 'status', 'label' => 'Status', 'operator_id' => ''],
                'group_info' => [
                  'identifier' => 'published_state',
                  'label' => 'Published state',
                  'default_group' => 'All',
                  'multiple' => FALSE,
                  'group_items' => [
                    1 => ['title' => 'Published', 'operator' => '=', 'value' => '1'],
                    2 => ['title' => 'Unpublished', 'operator' => '=', 'value' => '0'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ])->save();
    $cases = [
      [[], FALSE],
      [['published_state' => 'All'], FALSE],
      [['status' => '1'], FALSE],
      [['published_state' => '1'], TRUE],
    ];
    foreach ($cases as [$input, $expected]) {
      $view = Views::getView('grouped_view');
      self::assertNotNull($view);
      $view->setDisplay('default');
      $view->setExposedInput($input);
      $view->preExecute();
      $view->execute('default');
      self::assertSame($expected, ViewConditions::collect($view)['filters_applied'], \json_encode($input) ?: '');
    }
  }

  /**
   * A remembered choice of a View or display that is gone falls back.
   */
  public function testStalePreviewSelectionFallsBack(): void {
    $template = $this->createTemplate('stale_template', []);
    $this->createView('stale_view', 'stale_template');
    $view = View::load('stale_view');
    self::assertNotNull($view);
    $displays = $view->get('display');
    $displays['page_2'] = [
      'display_plugin' => 'page',
      'id' => 'page_2',
      'display_title' => 'Second',
      'display_options' => ['path' => 'stale-2'],
    ];
    $view->set('display', $displays)->save();
    $selection = $this->container->get(ViewTemplatePreviewSelection::class);

    $selection->set('stale_template', 'stale_view/page_2');
    self::assertSame('stale_view/page_2', $selection->getValue('stale_template'));

    // The display is removed: back to the display using the template.
    unset($displays['page_2']);
    $view->set('display', $displays)->save();
    self::assertSame('stale_view/default', $selection->getValue('stale_template'));
    $form = $this->container->get('form_builder')->getForm(ViewTemplatePreviewForm::class, $template);
    self::assertArrayHasKey($form[ViewTemplatePreviewForm::FIELD]['#default_value'], $form[ViewTemplatePreviewForm::FIELD]['#options']);

    // A missing View, or a disabled one, is not used either.
    $selection->set('stale_template', 'missing_view/default');
    self::assertSame('stale_view/default', $selection->getValue('stale_template'));
    $view->disable()->save();
    self::assertSame(ViewTemplatePreviewSelection::NONE, $selection->getValue('stale_template'));
    self::assertNull($selection->get('stale_template'));
    $form = $this->container->get('form_builder')->getForm(ViewTemplatePreviewForm::class, $template);
    self::assertSame(ViewTemplatePreviewSelection::NONE, $form[ViewTemplatePreviewForm::FIELD]['#default_value']);
  }

  /**
   * The editor preview varies by user, is not cached, and warns about misuse.
   */
  public function testPreviewCacheabilityAndWarning(): void {
    $rows = $this->item(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID);
    $repeater = $this->item('canvas_view_template_repeater.view_repeater');
    $rows_inside = ['parent_uuid' => $repeater['uuid'], 'slot' => 'item'] + $rows;
    $subscriber = $this->container->get('canvas_view_template.preview_subscriber');
    $respond = function (array $tree) use ($subscriber): array {
      $template = ViewTemplate::create(['id' => 'cache_check', 'label' => 'Cache check', 'component_tree' => $tree]);
      $request = new Request();
      $request->attributes->set('entity', $template);
      $envelope = new PreviewEnvelope(['#markup' => 'EDITOR_PLACEHOLDERS'], []);
      $event = new ViewEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $envelope);
      $subscriber->onView($event);
      $result = $event->getControllerResult();
      self::assertInstanceOf(PreviewEnvelope::class, $result);
      return $result->previewRenderArray;
    };

    $preview = $respond([$rows]);
    $cacheability = CacheableMetadata::createFromRenderArray($preview);
    self::assertContains('user', $cacheability->getCacheContexts());
    self::assertSame(0, $cacheability->getCacheMaxAge());
    $html = (string) $this->renderer->renderInIsolation($preview);
    self::assertStringContainsString('cvt-preview-hint', $html);
    self::assertStringNotContainsString('cvt-preview-warning', $html);

    $preview = $respond([$repeater, $rows_inside]);
    $html = (string) $this->renderer->renderInIsolation($preview);
    self::assertStringContainsString('cvt-preview-warning', $html);
    self::assertStringContainsString('A "View rows" cannot be placed inside a "View repeater"', \html_entity_decode($html));
  }

  /**
   * Renders a View's default display.
   *
   * @return array{0: string, 1: list<string>}
   *   The HTML and the cache tags.
   */
  private function renderView(string $view_id): array {
    $view = Views::getView($view_id);
    self::assertNotNull($view);
    $build = $view->buildRenderable('default');
    $html = (string) $this->renderer->renderInIsolation($build);
    return [$html, $build['#cache']['tags'] ?? []];
  }

  /**
   * Creates and saves a view template.
   */
  private function createTemplate(string $id, array $tree): ViewTemplate {
    $template = ViewTemplate::create(['id' => $id, 'label' => $id, 'component_tree' => $tree]);
    $template->save();
    return $template;
  }

  /**
   * Creates a View over nodes using a view template.
   */
  private function createView(string $id, ?string $template_id, array $pager = ['type' => 'some']): void {
    $options = [
      'title' => 'FALLBACK_TITLE',
      'row' => ['type' => 'fields'],
      'pager' => $pager,
      'sorts' => [
        'nid' => [
          'id' => 'nid',
          'table' => 'node_field_data',
          'field' => 'nid',
          'entity_type' => 'node',
          'entity_field' => 'nid',
          'plugin_id' => 'standard',
          'order' => 'ASC',
        ],
      ],
    ];
    $style = ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => $template_id]];
    if ($template_id !== NULL) {
      $options['style'] = $style;
    }
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_options' => $options],
      ],
    ])->save();
  }

  /**
   * Builds a root-level tree item for a component.
   */
  private function item(string $component_id): array {
    $component = Component::load($component_id);
    self::assertNotNull($component, $component_id);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $component->getComponentSource()->getDefaultExplicitInput(),
    ];
  }

}
