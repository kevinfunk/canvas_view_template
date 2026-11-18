<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas_view_template\Controller\ViewTemplateDuplicateController;
use Drupal\canvas_view_template\Controller\ViewTemplateLivePreviewController;
use Drupal\canvas_view_template\Controller\ViewTemplateUsageController;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\EventSubscriber\ViewTemplatePreviewSubscriber;
use Drupal\canvas_view_template\Form\ViewTemplatePreviewForm;
use Drupal\canvas_view_template\Hook\ViewTemplatePreviewHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewPreviewBuilder;
use Drupal\canvas_view_template\Preview\ViewTemplateLivePreview;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests previewing a view template with a View's real content.
 */
#[CoversClass(ViewTemplatePreviewSelection::class)]
#[CoversClass(ViewPreviewBuilder::class)]
#[CoversClass(ViewTemplateLivePreview::class)]
#[CoversClass(ViewTemplatePreviewForm::class)]
#[CoversClass(ViewTemplatePreviewSubscriber::class)]
#[CoversClass(ViewTemplateLivePreviewController::class)]
#[CoversClass(ViewTemplateUsage::class)]
#[CoversClass(ViewTemplatePreviewHooks::class)]
#[CoversClass(ViewTemplateUsageController::class)]
#[CoversClass(ViewTemplateDuplicateController::class)]
#[Group('canvas_view_template')]
final class ViewTemplatePreviewTest extends ViewTemplateKernelTestBase {

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
    $this->installSchema('system', ['sequences']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
    // The private temp store is per user.
    $this->setUpCurrentUser(['uid' => 2], ['access content', 'bypass node access']);

    // A View whose label starts lowercase, to check the order of the select.
    View::create([
      'id' => 'alpha_lowercase_view',
      'label' => 'alpha lowercase view',
      'base_table' => 'node_field_data',
      'display' => ['default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Default']],
    ])->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Node::create(['type' => 'article', 'title' => 'First article', 'status' => 1])->save();

    // "View item" shows the row's own render, keeping this test to the
    // preview code.
    $rows = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID);
    self::assertNotNull($rows);
    ViewTemplate::create([
      'id' => 'preview_test',
      'label' => 'Preview test',
      'component_tree' => [[
        'uuid' => '44444444-4444-4444-8444-444444444444',
        'component_id' => $rows->id(),
        'component_version' => $rows->getActiveVersion(),
        'inputs' => [],
      ],
      ],
    ])->save();
    View::create([
      'id' => 'preview_test_view',
      'label' => 'Preview test view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'preview_test'],
            ],
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_options' => ['path' => 'preview-test'],
        ],
      ],
    ])->save();
  }

  /**
   * Only well-formed `view/display` values are accepted.
   */
  #[DataProvider('selectionValues')]
  public function testParse(string $value, ?array $expected): void {
    self::assertSame($expected, ViewTemplatePreviewSelection::parse($value));
  }

  /**
   * Data provider for ::testParse().
   */
  public static function selectionValues(): array {
    return [
      'view and display' => ['content/page_1', ['view' => 'content', 'display' => 'page_1']],
      'legacy colon' => ['content:page_1', ['view' => 'content', 'display' => 'page_1']],
      'none' => ['none', NULL],
      'legacy none' => ['_none', NULL],
      'empty' => ['', NULL],
      'a group label' => ['test', NULL],
      'extra part' => ['a/b/c', NULL],
      'unsafe characters' => ['a/b c', NULL],
    ];
  }

  /**
   * The choice is remembered per template and can be cleared.
   */
  public function testSelectionRoundTrip(): void {
    $selection = $this->container->get(ViewTemplatePreviewSelection::class);
    self::assertSame('preview_test_view/page_1', $selection->getValue('preview_test'));
    self::assertSame(ViewTemplatePreviewSelection::NONE, $selection->getValue('other_template'));
    self::assertNull($selection->get('other_template'));

    $selection->set('preview_test', ViewTemplatePreviewSelection::NONE);
    self::assertSame(ViewTemplatePreviewSelection::NONE, $selection->getValue('preview_test'));
    self::assertNull($selection->get('preview_test'));

    $selection->set('preview_test', 'preview_test_view/default');
    self::assertSame(['view' => 'preview_test_view', 'display' => 'default'], $selection->get('preview_test'));

    $selection->set('preview_test', NULL);
    self::assertSame(['view' => 'preview_test_view', 'display' => 'default'], $selection->get('preview_test'));

    $selection->set('preview_test', 'nonsense');
    self::assertSame('preview_test_view/page_1', $selection->getValue('preview_test'));
  }

  /**
   * The builder returns the pieces of a display, or NULL if it can't run.
   */
  public function testBuilder(): void {
    $builder = $this->container->get(ViewPreviewBuilder::class);
    self::assertNull($builder->build('missing_view', 'default'));
    self::assertNull($builder->build('preview_test_view', 'missing_display'));

    $pieces = $builder->build('preview_test_view', 'page_1');
    self::assertNotNull($pieces);
    self::assertCount(1, $pieces['row_renders']);
    self::assertSame(0, $pieces['current_page']);
    self::assertSame('1', $pieces['summary']['@start']);
    self::assertSame('1', $pieces['summary']['@end']);
    self::assertSame('1', $pieces['summary']['@total']);
    self::assertSame('1', $pieces['summary']['@page_count']);
    self::assertSame(['title'], \array_keys($pieces['field_data']['rows'][0]));
    self::assertStringContainsString('First article', (string) $this->renderer->renderInIsolation($pieces['row_renders'][0]));
  }

  /**
   * The template renders with the chosen View's real content.
   */
  public function testLivePreview(): void {
    $template = ViewTemplate::load('preview_test');
    self::assertNotNull($template);
    $live = $this->container->get(ViewTemplateLivePreview::class);
    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', ViewTemplatePreviewSelection::NONE);
    self::assertNull($live->build($template), 'No View chosen: nothing live to show.');

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', 'preview_test_view/page_1');
    $build = $live->build($template);
    self::assertNotNull($build);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('First article', $html);
  }

  /**
   * The "View field" component offers a select of fields, from the best source.
   */
  public function testFieldSettingIsSelectOfFields(): void {
    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    $build_form = fn (ViewTemplate $template, array $values = []) => $source->buildComponentInstanceForm([], new FormState(), $component, 'u', $values, $template);
    $selection = $this->container->get(ViewTemplatePreviewSelection::class);
    $used = ViewTemplate::load('preview_test');

    $form = $build_form($used);
    self::assertSame('select', $form[ViewTemplateField::INPUT_FIELD]['#type']);
    self::assertArrayHasKey('title', $form[ViewTemplateField::INPUT_FIELD]['#options']);
    self::assertStringContainsString('chosen under Page data', (string) $form[ViewTemplateField::INPUT_FIELD]['#description']);

    $selection->set('preview_test', ViewTemplatePreviewSelection::NONE);
    $form = $build_form($used);
    self::assertSame('select', $form[ViewTemplateField::INPUT_FIELD]['#type']);
    self::assertArrayHasKey('title', $form[ViewTemplateField::INPUT_FIELD]['#options']);
    self::assertStringContainsString('views that use this template', (string) $form[ViewTemplateField::INPUT_FIELD]['#description']);

    $unused = ViewTemplate::create(['id' => 'unused_template', 'label' => 'Unused']);
    $unused->save();
    $form = $build_form($unused);
    self::assertSame('select', $form[ViewTemplateField::INPUT_FIELD]['#type']);
    self::assertArrayHasKey('title', $form[ViewTemplateField::INPUT_FIELD]['#options']);
    self::assertStringContainsString('views on this site', (string) $form[ViewTemplateField::INPUT_FIELD]['#description']);
    self::assertSame('', \array_key_first($form[ViewTemplateField::INPUT_FIELD]['#options']));

    $form = $build_form($unused, [ViewTemplateField::INPUT_FIELD => 'field_gone']);
    self::assertArrayHasKey('field_gone', $form[ViewTemplateField::INPUT_FIELD]['#options']);
    self::assertSame('field_gone', $form[ViewTemplateField::INPUT_FIELD]['#default_value']);
  }

  /**
   * The Views DOM id is stable across preview requests, so it doesn't flash.
   */
  public function testViewDomIdIsStableInEditorPreviews(): void {
    $preview_route = new RouteMatch('canvas.api.layout.post', new Route('/canvas/api/v0/layout/{entity_type}/{entity}'));
    $other_route = new RouteMatch('entity.node.canonical', new Route('/node/{node}'));
    $args = [];
    $dom_id = function (RouteMatch $route_match, ?ViewTemplatePreviewHooks $hooks = NULL) use (&$args): ?string {
      $view = Views::getView('preview_test_view');
      self::assertNotNull($view);
      ($hooks ?? new ViewTemplatePreviewHooks($route_match))->preView($view, 'page_1', $args);
      return $view->dom_id;
    };

    $first = $dom_id($preview_route);
    self::assertNotEmpty($first);
    self::assertSame($first, $dom_id($preview_route));

    $hooks = new ViewTemplatePreviewHooks($preview_route);
    self::assertSame($first, $dom_id($preview_route, $hooks));
    self::assertNotSame($first, $dom_id($preview_route, $hooks));

    // Elsewhere the id is left to Views, which makes it random.
    self::assertEmpty($dom_id($other_route));
  }

  /**
   * Finds the displays using a template, and not the ones that don't.
   */
  public function testUsage(): void {
    $usage = $this->container->get(ViewTemplateUsage::class);
    $found = $usage->displaysUsing('preview_test');
    self::assertSame(['preview_test_view/page_1'], \array_map(static fn (array $d): string => $d['view'] . '/' . $d['display'], $found));
    self::assertSame('Preview test view: Page', $found[0]['label']);
    self::assertSame([], $usage->displaysUsing('nothing_uses_this'));
  }

  /**
   * The usage route lists them as JSON, privately.
   */
  public function testUsageRoute(): void {
    $template = ViewTemplate::load('preview_test');
    $response = ViewTemplateUsageController::create($this->container)->usage($template);
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['Preview test view: Page'], \array_column($data['displays'], 'label'));
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * The route that re-renders the preview returns the live HTML, privately.
   */
  public function testLivePreviewRoute(): void {
    $template = ViewTemplate::load('preview_test');
    $controller = ViewTemplateLivePreviewController::create($this->container);

    $request = Request::create('/canvas-view-template/live-preview/preview_test');

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', ViewTemplatePreviewSelection::NONE);
    $response = $controller->render($template, $request);
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame('', $data['html'], 'No View chosen: nothing to show.');

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', 'preview_test_view/page_1');
    $response = $controller->render($template, $request);
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertStringContainsString('First article', $data['html']);
    // The files and settings the content needs come with it. The test theme
    // has none, so the lists may be empty.
    // @see \Drupal\Tests\canvas_view_template\Kernel\ViewTemplateLivePreviewAssetsTest
    self::assertIsArray($data['css']);
    self::assertIsArray($data['js']);
    self::assertArrayHasKey('settings', $data);
    foreach ($data['css'] as $url) {
      self::assertStringEndsWith('.css', \explode('?', $url)[0]);
    }
    self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * The Page data select lists displays flat, with values the editor can send.
   */
  public function testFormOptions(): void {
    $template = ViewTemplate::load('preview_test');
    $form = $this->container->get('form_builder')->getForm(ViewTemplatePreviewForm::class, $template);
    $options = $form[ViewTemplatePreviewForm::FIELD]['#options'];
    // Canvas's select sends a group's label instead of the value, so no groups.
    foreach ($options as $value => $label) {
      self::assertIsString($value);
      self::assertIsNotArray($label);
    }
    self::assertArrayHasKey(ViewTemplatePreviewSelection::NONE, $options);
    // Sorted by label ignoring case, so a lowercase View name isn't last.
    $labels = \array_map('strval', \array_slice($options, 1));
    $sorted = $labels;
    \natcasesort($sorted);
    self::assertSame(\array_values($sorted), \array_values($labels));
    self::assertSame('alpha_lowercase_view/default', \array_keys($labels)[0] ?? NULL);

    self::assertArrayNotHasKey('preview_test_view/default', $options);
    self::assertArrayHasKey('alpha_lowercase_view/default', $options);

    self::assertSame('Preview test view: Page (uses this template)', (string) $options['preview_test_view/page_1']);
    self::assertSame('preview_test_view/page_1', $form[ViewTemplatePreviewForm::FIELD]['#default_value']);
    self::assertSame('page_data_form', $form['#attributes']['data-form-id']);

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', ViewTemplatePreviewSelection::NONE);
    $form = $this->container->get('form_builder')->getForm(ViewTemplatePreviewForm::class, $template);
    self::assertSame(ViewTemplatePreviewSelection::NONE, $form[ViewTemplatePreviewForm::FIELD]['#default_value']);
  }

  /**
   * The editor's response carries both versions only when a View is chosen.
   */
  public function testSubscriberAddsLiveVersion(): void {
    $template = ViewTemplate::load('preview_test');
    $subscriber = $this->container->get('canvas_view_template.preview_subscriber');
    $original = ['#markup' => 'EDITOR_PLACEHOLDERS'];
    $respond = function () use ($template, $subscriber, $original): PreviewEnvelope {
      $request = new Request();
      $request->attributes->set('entity', $template);
      $event = new ViewEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new PreviewEnvelope($original, ['entity_form_fields' => ['id' => 'preview_test']]));
      $subscriber->onView($event);
      $result = $event->getControllerResult();
      self::assertInstanceOf(PreviewEnvelope::class, $result);
      return $result;
    };

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', ViewTemplatePreviewSelection::NONE);
    $result = $respond();
    self::assertSame(ViewTemplatePreviewSelection::NONE, $result->additionalData['entity_form_fields'][ViewTemplatePreviewForm::FIELD]);
    $preview = $result->previewRenderArray;
    $html = (string) $this->renderer->renderInIsolation($preview);
    self::assertStringContainsString('EDITOR_PLACEHOLDERS', $html);
    self::assertStringContainsString('cvt-preview-hint', $html);
    self::assertStringContainsString('Preview with view', $html);
    self::assertStringNotContainsString('cvt-live-preview', $html);

    $this->container->get(ViewTemplatePreviewSelection::class)->set('preview_test', 'preview_test_view/page_1');
    $result = $respond();
    self::assertSame('preview_test_view/page_1', $result->additionalData['entity_form_fields'][ViewTemplatePreviewForm::FIELD]);
    $preview = $result->previewRenderArray;
    $html = (string) $this->renderer->renderInIsolation($preview);
    self::assertStringContainsString('EDITOR_PLACEHOLDERS', $html);
    self::assertStringContainsString('First article', $html);
    self::assertStringContainsString('cvt-edit-preview', $html);
    self::assertStringContainsString('cvt-live-preview', $html);
    self::assertStringContainsString('/canvas-view-template/live-preview/preview_test', $html);
  }

  /**
   * What the conditions need to know about a view comes from the view itself.
   */
  public function testConditionFacts(): void {
    $built = $this->container->get(ViewPreviewBuilder::class)->build('preview_test_view', 'page_1');
    self::assertNotNull($built);
    self::assertSame([
      'has_results' => TRUE,
      'multiple_pages' => FALSE,
      'first_page' => TRUE,
      'filters_applied' => FALSE,
    ], $built['facts']);
    self::assertSame(['/node/1'], $built['item_urls']);

    // An exposed filter counts as applied only once a value is submitted.
    View::create([
      'id' => 'filtered_view',
      'label' => 'Filtered view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
            'filters' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'string',
                'operator' => 'contains',
                'value' => '',
                'exposed' => TRUE,
                'expose' => ['identifier' => 'title', 'label' => 'Title', 'operator_id' => 'title_op'],
              ],
            ],
          ],
        ],
      ],
    ])->save();
    foreach ([[[], FALSE], [['title' => ''], FALSE], [['title' => 'First'], TRUE]] as [$input, $expected]) {
      $view = Views::getView('filtered_view');
      self::assertNotNull($view);
      $view->setDisplay('default');
      $view->setExposedInput($input);
      $view->preExecute();
      $view->execute('default');
      self::assertSame($expected, ViewConditions::collect($view)['filters_applied'], \json_encode($input) ?: '');
    }
  }

  /**
   * A repeater inside another gets a warning in the preview while editing.
   */
  public function testPreviewWarnsAboutNestedRepeater(): void {
    $repeater = Component::load('canvas_view_template_repeater.view_repeater');
    self::assertNotNull($repeater);
    $outer = [
      'uuid' => '55555555-5555-4555-8555-555555555555',
      'component_id' => $repeater->id(),
      'component_version' => $repeater->getActiveVersion(),
      'inputs' => $repeater->getComponentSource()->getDefaultExplicitInput(),
    ];
    $inner = [
      'uuid' => '66666666-6666-4666-8666-666666666666',
      'parent_uuid' => $outer['uuid'],
      'slot' => 'item',
    ] + $outer;
    $subscriber = $this->container->get('canvas_view_template.preview_subscriber');
    $html = function (array $tree) use ($subscriber): string {
      $template = ViewTemplate::create(['id' => 'nested_check', 'label' => 'Nested check', 'component_tree' => $tree]);
      $request = new Request();
      $request->attributes->set('entity', $template);
      $event = new ViewEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new PreviewEnvelope(['#markup' => 'EDITOR_PLACEHOLDERS'], []));
      $subscriber->onView($event);
      $result = $event->getControllerResult();
      self::assertInstanceOf(PreviewEnvelope::class, $result);
      $preview = $result->previewRenderArray;
      return (string) $this->renderer->renderInIsolation($preview);
    };

    self::assertStringNotContainsString('cvt-preview-warning', $html([$outer]));
    $warned = $html([$outer, $inner]);
    self::assertStringContainsString('cvt-preview-warning', $warned);
    self::assertStringContainsString('cannot be placed inside another', $warned);
    self::assertStringContainsString('EDITOR_PLACEHOLDERS', $warned);
  }

  /**
   * The usage route lists every template's displays at once.
   */
  public function testUsageOfAllTemplates(): void {
    ViewTemplate::create(['id' => 'unused_template', 'label' => 'Unused'])->save();
    $response = ViewTemplateUsageController::create($this->container)->all();
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['preview_test', 'unused_template'], \array_keys($data['templates']));
    self::assertSame('preview_test_view', $data['templates']['preview_test'][0]['view']);
    self::assertSame([], $data['templates']['unused_template']);
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * A template is copied under a new name, from its draft if it has one.
   */
  public function testDuplicate(): void {
    $original = ViewTemplate::load('preview_test');
    $original->set('description', 'The original')->save();
    $controller = ViewTemplateDuplicateController::create($this->container);
    $send = static fn (array $body): Request => Request::create('/', 'POST', [], [], [], [], (string) \json_encode($body));

    $response = $controller->duplicate($original, $send(['id' => 'preview_copy', 'label' => ' Preview copy ']));
    self::assertSame(201, $response->getStatusCode());
    self::assertSame(['id' => 'preview_copy', 'label' => 'Preview copy'], \json_decode((string) $response->getContent(), TRUE));
    $copy = ViewTemplate::load('preview_copy');
    self::assertNotNull($copy);
    self::assertSame('Preview copy', $copy->label());
    self::assertSame('The original', $copy->get('description'));
    self::assertEquals($original->getComponentTree()->getValue(), $copy->getComponentTree()->getValue());
    self::assertNotNull(ViewTemplate::load('preview_test'));
    self::assertSame([], $this->container->get(ViewTemplateUsage::class)->displaysUsing('preview_copy'));

    $response = $controller->duplicate($original, $send(['id' => 'preview_copy', 'label' => 'Again']));
    self::assertSame(409, $response->getStatusCode());
    self::assertStringContainsString('already exists', (string) $response->getContent());

    foreach ([['id' => 'Bad Name', 'label' => 'X'], ['id' => '1abc', 'label' => 'X'], ['id' => 'fine', 'label' => ' ']] as $body) {
      self::assertSame(422, $controller->duplicate($original, $send($body))->getStatusCode(), (string) \json_encode($body));
    }
    self::assertNull(ViewTemplate::load('fine'));
  }

  /**
   * A template that Views use cannot be deleted, by anyone, until none do.
   */
  public function testTemplateInUseCannotBeDeleted(): void {
    $admin = $this->createUser(['administer view template']);
    ViewTemplate::create(['id' => 'unused_template', 'label' => 'Unused'])->save();
    $used = ViewTemplate::load('preview_test');
    $unused = ViewTemplate::load('unused_template');

    $result = $used->access('delete', $admin, TRUE);
    self::assertTrue($result->isForbidden());
    self::assertStringContainsString('Preview test view: Page', (string) $result->getReason());
    self::assertTrue($unused->access('delete', $admin));
    self::assertTrue($used->access('update', $admin));

    $view = View::load('preview_test_view');
    $displays = $view->get('display');
    $displays['default']['display_options']['style'] = ['type' => 'default', 'options' => []];
    $view->set('display', $displays)->save();
    $this->container->get('kernel')->rebuildContainer();
    $used = ViewTemplate::load('preview_test');
    self::assertTrue($used->access('delete', $admin));
  }

}
