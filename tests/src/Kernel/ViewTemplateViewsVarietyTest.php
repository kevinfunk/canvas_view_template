<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Cache\Cache;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests Views of the shapes real sites build, rendered through a template.
 *
 * Each test builds one View feature (relationships, aggregation, contextual
 * filters, attachments, AJAX, cache plugins, field rewriting, other row and
 * display types) and checks that the template shows it correctly.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateViewsVarietyTest extends ViewTemplateKernelTestBase {

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
    $this->installNodeSupport();
    $this->installEntitySchema('user');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    User::create(['uid' => 2, 'name' => 'ann', 'status' => 1])->save();
    User::create(['uid' => 3, 'name' => 'bob', 'status' => 1])->save();
    foreach ([['Alpha', 'article', 2], ['Bravo', 'article', 3], ['Charlie', 'page', 2]] as [$title, $type, $uid]) {
      Node::create(['type' => $type, 'title' => $title, 'uid' => $uid, 'status' => TRUE])->save();
    }
    // Not uid 1, who passes every access check. Profiles are visible so that
    // rows over users get links.
    $this->setCurrentUser($this->createUser(['access content', 'access user profiles'], NULL, FALSE, ['uid' => 9]));
  }

  /**
   * The options every test View starts from; tests override what they test.
   *
   * @param string $template_id
   *   The view template the display uses.
   * @param array<string, mixed> $overrides
   *   Display options that replace or add to the defaults.
   *
   * @return array<string, mixed>
   *   The default display's options.
   */
  private function displayOptions(string $template_id, array $overrides = []): array {
    return $overrides + [
      'style' => [
        'type' => ViewTemplateStyle::PLUGIN_ID,
        'options' => [ViewTemplateStyle::OPTION_KEY => $template_id],
      ],
      'row' => ['type' => 'fields'],
      'pager' => ['type' => 'none', 'options' => []],
      'fields' => ['title' => $this->titleField()],
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
    ];
  }

  /**
   * A node title field handler config, with optional overrides.
   *
   * @param array<string, mixed> $overrides
   *   Replaces keys of the handler config.
   *
   * @return array<string, mixed>
   *   The handler config.
   */
  private function titleField(array $overrides = []): array {
    return $overrides + [
      'id' => 'title',
      'table' => 'node_field_data',
      'field' => 'title',
      'entity_type' => 'node',
      'entity_field' => 'title',
      'plugin_id' => 'field',
      'label' => '',
      'type' => 'string',
      'settings' => ['link_to_entity' => FALSE],
    ];
  }

  /**
   * Saves a template that shows one field of every row, and a View using it.
   *
   * @param string $field
   *   The field ID "View field" shows for each row.
   * @param array<string, mixed> $overrides
   *   Display options that replace the defaults.
   * @param array<string, array<string, mixed>> $displays
   *   More displays, keyed by ID.
   * @param string $base_table
   *   The View's base table.
   */
  private function createFieldView(string $field, array $overrides = [], array $displays = [], string $base_table = 'node_field_data'): void {
    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $inputs = [
      ViewTemplateField::INPUT_FIELD => $field,
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    $item = $this->treeItem(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, $inputs);
    $this->createViewWithTree([$repeater, $item], $overrides, $displays, $base_table);
  }

  /**
   * Saves a template with the given tree and a View that uses it.
   *
   * @param array<int, array<string, mixed>> $tree
   *   The template's component tree.
   * @param array<string, mixed> $overrides
   *   Display options that replace the defaults.
   * @param array<string, array<string, mixed>> $displays
   *   More displays, keyed by ID.
   * @param string $base_table
   *   The View's base table.
   */
  private function createViewWithTree(array $tree, array $overrides = [], array $displays = [], string $base_table = 'node_field_data'): void {
    ViewTemplate::create(['id' => 'variety', 'label' => 'Variety', 'component_tree' => $tree])->save();
    $default = [
      'display_plugin' => 'default',
      'id' => 'default',
      'display_options' => $this->displayOptions('variety', $overrides),
    ];
    View::create([
      'id' => 'variety_view',
      'label' => 'Variety view',
      'base_table' => $base_table,
      'display' => ['default' => $default] + $displays,
    ])->save();
  }

  /**
   * Renders the View's display for a request.
   *
   * @param string $display
   *   The display ID.
   * @param array<string, mixed> $query
   *   The query string.
   * @param array<int, string> $args
   *   Contextual filter values.
   *
   * @return array{0: string, 1: array}
   *   The HTML and the render array after rendering (cache, attachments).
   */
  private function renderView(string $display = 'default', array $query = [], array $args = []): array {
    $request = Request::create('/variety', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $view = Views::getView('variety_view');
      self::assertNotNull($view);
      $build = $view->buildRenderable($display, $args);
      $html = (string) $this->renderer->renderInIsolation($build);
      return [$html, $build];
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * A field of a related entity (the node's author) shows for every row.
   */
  public function testRelationshipField(): void {
    $this->createFieldView('name', [
      'relationships' => [
        'uid' => [
          'id' => 'uid',
          'table' => 'node_field_data',
          'field' => 'uid',
          'plugin_id' => 'standard',
          'required' => FALSE,
        ],
      ],
      'fields' => [
        'name' => [
          'id' => 'name',
          'table' => 'users_field_data',
          'field' => 'name',
          'relationship' => 'uid',
          'entity_type' => 'user',
          'entity_field' => 'name',
          'plugin_id' => 'field',
          'label' => '',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
        ],
      ],
    ]);
    [$html] = $this->renderView();
    self::assertSame(2, \substr_count($html, '>ann<'), 'ann wrote two nodes.');
    self::assertSame(1, \substr_count($html, '>bob<'));
  }

  /**
   * Aggregated rows have no entity: the values show, and no link is made.
   */
  public function testAggregatedRows(): void {
    $count = [
      'id' => 'nid',
      'table' => 'node_field_data',
      'field' => 'nid',
      'entity_type' => 'node',
      'entity_field' => 'nid',
      'plugin_id' => 'field',
      'group_type' => 'count',
      'label' => '',
      'type' => 'number_integer',
      'settings' => [],
    ];
    $type = [
      'id' => 'type',
      'table' => 'node_field_data',
      'field' => 'type',
      'entity_type' => 'node',
      'entity_field' => 'type',
      'plugin_id' => 'field',
      'label' => '',
      'type' => 'entity_reference_label',
      'settings' => ['link' => FALSE],
    ];
    $sorts = [
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'plugin_id' => 'standard',
        'order' => 'ASC',
      ],
    ];
    $this->createFieldView('nid', [
      'group_by' => TRUE,
      'fields' => ['type' => $type, 'nid' => $count],
      'sorts' => $sorts,
    ]);
    [$html] = $this->renderView();
    // Two articles and one page, one row each.
    self::assertStringContainsString('>2<', $html);
    self::assertStringContainsString('>1<', $html);
  }

  /**
   * A contextual filter filters, and its title override reaches the template.
   */
  public function testContextualFilterAndTitleOverride(): void {
    $this->createViewWithTree(
      [
        $this->treeItem(ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
      [
        'arguments' => [
          'type' => [
            'id' => 'type',
            'table' => 'node_field_data',
            'field' => 'type',
            'entity_type' => 'node',
            'entity_field' => 'type',
            'plugin_id' => 'string',
            'default_action' => 'empty',
            'title_enable' => TRUE,
            'title' => 'Content of type {{ arguments.type }}',
          ],
        ],
      ],
    );
    [$html] = $this->renderView('default', [], ['article']);
    self::assertStringContainsString('Content of type Article', $html);
    self::assertStringContainsString('Alpha', $html);
    self::assertStringContainsString('Bravo', $html);
    self::assertStringNotContainsString('Charlie', $html);

    // No match: the View's "empty" text, not an error.
    [$html] = $this->renderView('default', [], ['nothing']);
    self::assertStringContainsString('NO_RESULTS_TEXT', $html);
  }

  /**
   * An attachment display's rows show where the template places attachments.
   */
  public function testAttachmentDisplay(): void {
    $this->createViewWithTree(
      [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ATTACHMENTS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
      [],
      [
        'attachment_1' => [
          'display_plugin' => 'attachment',
          'id' => 'attachment_1',
          'display_options' => [
            'displays' => ['default' => 'default'],
            'attachment_position' => 'before',
            'inherit_arguments' => TRUE,
            'inherit_exposed_filters' => FALSE,
            'inherit_pager' => FALSE,
            'render_pager' => FALSE,
            'pager' => ['type' => 'some', 'options' => ['items_per_page' => 1, 'offset' => 2]],
            'style' => ['type' => 'default', 'options' => []],
            'row' => ['type' => 'fields'],
            'defaults' => ['style' => FALSE, 'row' => FALSE, 'pager' => FALSE],
          ],
        ],
      ],
    );
    [$html] = $this->renderView();
    // The attachment shows the third node once, and the main rows show all.
    self::assertSame(2, \substr_count($html, 'Charlie'), $html);
  }

  /**
   * A View with AJAX on keeps what Views' JavaScript needs.
   */
  public function testAjaxView(): void {
    $this->createFieldView('title', ['use_ajax' => TRUE]);
    [$html, $build] = $this->renderView();
    self::assertMatchesRegularExpression('/class="[^"]*js-view-dom-id-/', $html);
    self::assertContains('views/views.ajax', $build['#attached']['library'] ?? []);
    self::assertArrayHasKey('views', $build['#attached']['drupalSettings'] ?? []);
  }

  /**
   * A time-based cache plugin limits how long the output may be cached.
   */
  public function testTimeBasedCachePluginLimitsMaxAge(): void {
    $this->createFieldView('title', [
      'cache' => ['type' => 'time', 'options' => ['results_lifespan' => 3600, 'output_lifespan' => 3600]],
    ]);
    [, $build] = $this->renderView();
    $max_age = $build['#cache']['max-age'] ?? Cache::PERMANENT;
    self::assertNotSame(Cache::PERMANENT, $max_age);
    self::assertLessThanOrEqual(3600, $max_age);
  }

  /**
   * Rewritten and hidden fields show as Views renders them.
   */
  public function testRewrittenFields(): void {
    $this->createFieldView('title', [
      'fields' => [
        'nid' => $this->titleField([
          'id' => 'nid',
          'field' => 'nid',
          'entity_field' => 'nid',
          'type' => 'number_integer',
          'settings' => [],
          'exclude' => TRUE,
        ]),
        'title' => $this->titleField([
          'alter' => ['alter_text' => TRUE, 'text' => 'Item {{ nid }}: {{ title }}'],
        ]),
      ],
    ]);
    [$html] = $this->renderView();
    self::assertStringContainsString('Item 1: Alpha', $html);
    self::assertStringContainsString('Item 3: Charlie', $html);
  }

  /**
   * A block display of the same View renders through the same template.
   */
  public function testBlockDisplay(): void {
    $this->createFieldView('title', [], [
      'block_1' => ['display_plugin' => 'block', 'id' => 'block_1', 'display_options' => []],
    ]);
    [$html] = $this->renderView('block_1');
    self::assertStringContainsString('Alpha', $html);
    self::assertStringContainsString('Charlie', $html);
  }

  /**
   * A View over users links each row to the user's page.
   */
  public function testNonNodeView(): void {
    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $link = $this->treeItem(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $inputs = [
      ViewTemplateField::INPUT_FIELD => 'name',
      ViewTemplateField::INPUT_ELEMENT => 'span',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    $name = $this->treeItem(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID, $link['uuid'], ViewTemplateItemLink::SLOT_NAME, $inputs);
    $this->createViewWithTree([$repeater, $link, $name], [
      'fields' => [
        'name' => [
          'id' => 'name',
          'table' => 'users_field_data',
          'field' => 'name',
          'entity_type' => 'user',
          'entity_field' => 'name',
          'plugin_id' => 'field',
          'label' => '',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
        ],
      ],
      'sorts' => [],
    ], [], 'users_field_data');
    [$html] = $this->renderView();
    self::assertStringContainsString('>ann<', $html);
    self::assertStringContainsString('>bob<', $html);
    self::assertStringContainsString('/user/2', $html);
  }

  /**
   * The "Rendered entity" row plugin shows each node as the entity renders.
   */
  public function testRenderedEntityRows(): void {
    $this->createViewWithTree(
      [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
      [
        'row' => ['type' => 'entity:node', 'options' => ['view_mode' => 'teaser']],
        'fields' => [],
      ],
    );
    [$html] = $this->renderView();
    // One teaser per node, each with its title as a heading and a link.
    self::assertSame(3, \substr_count($html, '<h2>'));
    foreach (['/node/1' => 'Alpha', '/node/2' => 'Bravo', '/node/3' => 'Charlie'] as $path => $title) {
      self::assertSame(1, \substr_count($html, '<span>' . $title . '</span>'), $title);
      self::assertStringContainsString('href="' . $path . '"', $html);
    }
  }

  /**
   * The exposed form shows, keeps what was entered, and counts as applied.
   */
  public function testExposedFilterForm(): void {
    $this->createViewWithTree(
      [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
      [
        'filters' => [
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
    );
    [$html] = $this->renderView('default', ['search' => 'brav']);
    self::assertStringContainsString('<form', $html);
    self::assertMatchesRegularExpression('/name="search"[^>]*value="brav"|value="brav"[^>]*name="search"/', $html);
    self::assertStringContainsString('Bravo', $html);
    self::assertStringNotContainsString('Alpha', $html);
  }

  /**
   * A full pager that shows everything has no pager and no second page.
   */
  public function testFullPagerShowingEverything(): void {
    $this->createViewWithTree(
      [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
      ],
      ['pager' => ['type' => 'full', 'options' => ['items_per_page' => 0]]],
    );
    [$html] = $this->renderView();
    foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
      self::assertStringContainsString($title, $html);
    }
    self::assertStringNotContainsString('pager', $html);
  }

  /**
   * Markup in a field value is escaped or filtered, never run.
   */
  public function testMarkupInFieldValuesIsNotRun(): void {
    Node::create([
      'type' => 'article',
      'title' => '<script>alert("x")</script><b>bold</b>',
      'uid' => 2,
      'status' => TRUE,
    ])->save();
    $this->createFieldView('title');
    [$html] = $this->renderView();
    self::assertStringNotContainsString('<script', $html);
    self::assertStringNotContainsString('<b>', $html);
  }

}
