<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests that a View shown through a template never gets another's cache entry.
 *
 * The View is rendered through the real render cache as different users, on
 * different pages and with different filter values. Each variant must show its
 * own rows however the cache was warmed. A missing cache context would show up
 * here as the wrong rows.
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[Group('canvas_view_template')]
final class ViewTemplateFrontEndCacheTest extends ViewTemplateKernelTestBase {

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
   * A user who sees published content.
   */
  private AccountInterface $reader;

  /**
   * A user who also sees unpublished content.
   */
  private AccountInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->installEntitySchema('user');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    // Node IDs follow this order, and the View sorts by them: the unpublished
    // node is second, so it changes what both pages show for an editor.
    foreach (['Alpha' => TRUE, 'Secret' => FALSE, 'Bravo' => TRUE, 'Charlie' => TRUE, 'Delta' => TRUE] as $title => $published) {
      Node::create(['type' => 'article', 'title' => $title, 'status' => $published])->save();
    }
    // Explicit IDs: the first user a kernel test creates would be uid 1, who
    // passes every access check.
    $this->reader = $this->createUser(['access content'], NULL, FALSE, ['uid' => 2]);
    $this->editor = $this->createUser(['access content', 'bypass node access'], NULL, FALSE, ['uid' => 3]);

    ViewTemplate::create([
      'id' => 'cache_template',
      'label' => 'Cache template',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
      ],
    ])->save();
    View::create([
      'id' => 'cache_view',
      'label' => 'Cache view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'cache_template'],
            ],
            'row' => ['type' => 'fields'],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 2]],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
                'label' => '',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
            ],
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
            'filters' => [
              // What the standard node Views add: unpublished nodes are for
              // users who may see them.
              'status_extra' => [
                'id' => 'status_extra',
                'table' => 'node_field_data',
                'field' => 'status_extra',
                'entity_type' => 'node',
                'plugin_id' => 'node_status',
                'group' => 1,
              ],
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'string',
                'operator' => 'contains',
                'exposed' => TRUE,
                'expose' => ['identifier' => 'search', 'label' => 'Title', 'remember' => FALSE],
              ],
            ],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Renders the View through the render cache as an account, for a request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who is viewing.
   * @param array<string, mixed> $query
   *   The query string: `page` and the exposed filter `search`.
   *
   * @return string
   *   The HTML.
   */
  private function renderAs(AccountInterface $account, array $query = []): string {
    $stack = $this->container->get('request_stack');
    // The exposed filter's form needs a session, as a real request has.
    $request = Request::create('/cache-view', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($request);
    $this->setCurrentUser($account);
    try {
      $view = Views::getView('cache_view');
      self::assertNotNull($view);
      $build = $view->buildRenderable('default');
      self::assertNotEmpty($build['#cache']['keys'] ?? [], 'The View is not render cached, so this test proves nothing.');
      return (string) $this->renderer->renderRoot($build);
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Every variant shows its own rows, whatever the cache holds.
   */
  public function testEachUserPageAndFilterGetsItsOwnOutput(): void {
    // Who views, the query string, the titles that must and must not show.
    $variants = [
      'reader, page 1' => [$this->reader, [], ['Alpha', 'Bravo'], ['Secret', 'Charlie']],
      'reader, page 2' => [$this->reader, ['page' => 1], ['Charlie', 'Delta'], ['Alpha', 'Secret']],
      'editor, page 1' => [$this->editor, [], ['Alpha', 'Secret'], ['Bravo', 'Charlie']],
      'editor, page 2' => [$this->editor, ['page' => 1], ['Bravo', 'Charlie'], ['Alpha', 'Secret']],
      'reader, filtered' => [$this->reader, ['search' => 'char'], ['Charlie'], ['Alpha', 'Bravo', 'Delta']],
      'editor, filtered' => [$this->editor, ['search' => 'cret'], ['Secret'], ['Alpha', 'Charlie']],
    ];
    // Forward, then backward: the cache is warm by the second pass, and a
    // variant that was only correct because it ran first would fail here.
    foreach ([$variants, \array_reverse($variants, TRUE), $variants] as $pass => $order) {
      foreach ($order as $name => [$account, $query, $shown, $hidden]) {
        $html = $this->renderAs($account, $query);
        foreach ($shown as $title) {
          self::assertStringContainsString($title, $html, \sprintf('%s shows %s (pass %d).', $name, $title, $pass));
        }
        foreach ($hidden as $title) {
          self::assertStringNotContainsString($title, $html, \sprintf('%s must not show %s (pass %d).', $name, $title, $pass));
        }
      }
    }
  }

}
