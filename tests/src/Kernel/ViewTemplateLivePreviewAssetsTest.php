<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas_view_template\Controller\ViewTemplateLivePreviewController;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StackMiddleware\AjaxPageState;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests that the live preview route only returns assets the page lacks.
 */
#[CoversClass(ViewTemplateLivePreviewController::class)]
#[Group('canvas_view_template')]
final class ViewTemplateLivePreviewAssetsTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  private const DATE_LIBRARY = 'core/components.canvas_test_sdc--date';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'canvas_test_sdc',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->setUpCurrentUser(['uid' => 2], ['access content', 'bypass node access']);
    $this->container->get(ComponentSourceManager::class)
      ->generateComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, ['canvas_test_sdc:date']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Node::create(['type' => 'article', 'title' => 'First article', 'status' => 1])->save();

    $item = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID);
    $date = Component::load('sdc.canvas_test_sdc.date');
    self::assertNotNull($item);
    self::assertNotNull($date);
    ViewTemplate::create([
      'id' => 'assets_test',
      'label' => 'Assets test',
      'component_tree' => [
        [
          'uuid' => '44444444-4444-4444-8444-444444444444',
          'component_id' => $item->id(),
          'component_version' => $item->getActiveVersion(),
          'inputs' => [],
        ],
        [
          'uuid' => '55555555-5555-4555-8555-555555555555',
          'component_id' => $date->id(),
          'component_version' => $date->getActiveVersion(),
          'inputs' => ['caption' => 'Birthday'],
        ],
      ],
    ])->save();
    View::create([
      'id' => 'assets_test_view',
      'label' => 'Assets test view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'assets_test'],
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
          'display_options' => ['path' => 'assets-test'],
        ],
      ],
    ])->save();
    $this->container->get(ViewTemplatePreviewSelection::class)->set('assets_test', 'assets_test_view/page_1');
  }

  /**
   * Without a page state, the component's own files come back.
   */
  public function testComponentAssetsReturned(): void {
    $data = $this->fetch([]);
    self::assertStringContainsString('First article', $data['html']);
    self::assertTrue($this->has($data['css'], 'components/date/date.css'));
    self::assertTrue($this->has($data['js'], 'components/date/date.js'));
    foreach ($data['css'] as $url) {
      self::assertStringEndsWith('.css', \explode('?', $url)[0]);
    }
    self::assertArrayHasKey('settings', $data);
    self::assertArrayNotHasKey('path', $data['settings'], "The JSON route's own path is not the page's.");
  }

  /**
   * Libraries the page already has are left out; new ones still come back.
   */
  public function testAlreadyLoadedLibrariesLeftOut(): void {
    $data = $this->fetch(['ajax_page_state' => ['libraries' => UrlHelper::compressQueryParameter('core/drupal,core/drupalSettings')]]);
    self::assertTrue($this->has($data['css'], 'components/date/date.css'), 'A library the page lacks still comes back.');
    self::assertTrue($this->has($data['js'], 'components/date/date.js'));
    // With core/drupalSettings on the page, the merged settings come back.
    self::assertArrayHasKey('pluralDelimiter', $data['settings']);
    self::assertArrayNotHasKey('path', $data['settings']);
    self::assertStringContainsString('First article', $data['html']);

    $data = $this->fetch(['ajax_page_state' => ['libraries' => UrlHelper::compressQueryParameter('core/drupal,' . self::DATE_LIBRARY)]]);
    self::assertFalse($this->has($data['css'], 'components/date/date.css'));
    self::assertFalse($this->has($data['js'], 'components/date/date.js'));
    self::assertArrayHasKey('settings', $data);
  }

  /**
   * The page state isn't taken as exposed input or kept in the query.
   */
  public function testPageStateRemovedFromQuery(): void {
    $request = NULL;
    $this->fetch(['ajax_page_state' => ['libraries' => 'core/drupal']], $request);
    self::assertInstanceOf(Request::class, $request);
    self::assertFalse($request->query->has('ajax_page_state'));
  }

  /**
   * Requests the live preview through the middleware that reads page state.
   *
   * @param array<string, mixed> $query
   *   The query string.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   Set to the request made.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function fetch(array $query, ?Request &$request = NULL): array {
    $template = ViewTemplate::load('assets_test');
    self::assertNotNull($template);
    $controller = ViewTemplateLivePreviewController::create($this->container);
    $inner = new class ($controller, $template) implements HttpKernelInterface {

      public function __construct(
        private readonly ViewTemplateLivePreviewController $controller,
        private readonly ViewTemplate $template,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return $this->controller->render($this->template, $request);
      }

    };
    $request = Request::create('/canvas-view-template/live-preview/assets_test', 'GET', $query);
    $request->setSession($this->container->get('session'));
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $response = (new AjaxPageState($inner))->handle($request);
    }
    finally {
      $stack->pop();
    }
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * Whether one of the URLs points at the given file.
   *
   * @param list<string> $urls
   *   URLs.
   * @param string $suffix
   *   The end of the file path.
   */
  private function has(array $urls, string $suffix): bool {
    foreach ($urls as $url) {
      if (\str_ends_with(\explode('?', $url)[0], $suffix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
