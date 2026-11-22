<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Controller\ViewTemplateUsageController;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\ViewFieldChoices;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the module stays fast and small on a site with many Views.
 *
 * The panel, the delete check and the "View field" form read every View's
 * configuration. A site can have hundreds of Views, so each of these must grow
 * with the number of Views and templates, not with their product. The budgets
 * are at least ten times what a run takes, so a slow machine does not fail them
 * but a scan repeated for every View does.
 */
#[Group('canvas_view_template')]
final class ViewTemplateScaleTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  private const int VIEWS = 500;

  private const int TEMPLATES = 25;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
    'views',
    'views_ui',
  ];

  /**
   * An account that may edit Views and templates.
   */
  private AccountInterface $admin;

  /**
   * An account that may only see content.
   */
  private AccountInterface $viewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->installEntitySchema('user');
    for ($t = 0; $t < self::TEMPLATES; $t++) {
      ViewTemplate::create(['id' => 't' . $t, 'label' => 'Template ' . $t])->save();
    }
    // Written as raw config: saving 500 Views through the entity API takes
    // seconds each in a test, and is not what is measured.
    $storage = $this->container->get('config.storage');
    for ($v = 0; $v < self::VIEWS; $v++) {
      $storage->write('views.view.v' . $v, $this->viewConfig($v));
    }
    $this->container->get('config.factory')->reset();
    // Not uid 1, who passes every access check.
    $this->admin = $this->createUser(['administer views', 'administer view templates'], NULL, FALSE, ['uid' => 5]);
    $this->viewer = $this->createUser(['access content'], NULL, FALSE, ['uid' => 6]);
  }

  /**
   * The config of one View: eight fields, three displays, half use a template.
   *
   * @return array<string, mixed>
   *   Config data.
   */
  private function viewConfig(int $index): array {
    $fields = [];
    for ($f = 0; $f < 8; $f++) {
      $fields['f' . $f] = [
        'id' => 'f' . $f,
        'table' => 'node_field_data',
        'field' => 'title',
        'plugin_id' => 'field',
        'label' => 'Field ' . $f,
      ];
    }
    $style = ['type' => 'default'];
    if ($index % 2 === 0) {
      $style = [
        'type' => ViewTemplateStyle::PLUGIN_ID,
        'options' => [ViewTemplateStyle::OPTION_KEY => 't' . ($index % self::TEMPLATES)],
      ];
    }
    return View::create([
      'id' => 'v' . $index,
      'label' => 'View ' . $index,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => ['style' => $style, 'fields' => $fields],
        ],
        'block_1' => ['display_plugin' => 'block', 'id' => 'block_1', 'display_options' => []],
        'block_2' => ['display_plugin' => 'block', 'id' => 'block_2', 'display_options' => []],
      ],
    ])->toArray();
  }

  /**
   * Runs a callback and fails if it takes longer than the budget.
   *
   * @return int
   *   The memory the callback added, in bytes.
   */
  private function assertWithin(float $seconds, callable $callback, string $what): int {
    \gc_collect_cycles();
    $memory = \memory_get_usage();
    $start = \microtime(TRUE);
    $callback();
    $taken = \microtime(TRUE) - $start;
    self::assertLessThan($seconds, $taken, \sprintf('%s took %.2fs for %d Views (budget %.1fs).', $what, $taken, self::VIEWS, $seconds));
    return \memory_get_usage() - $memory;
  }

  /**
   * Finding which Views use which templates is one pass over the Views.
   */
  public function testUsageScanIsOnePassOverTheViews(): void {
    $usage = $this->container->get(ViewTemplateUsage::class);
    $by_template = [];
    $this->assertWithin(1.0, function () use ($usage, &$by_template): void {
      $by_template = $usage->displaysByTemplate();
    }, 'The usage scan');
    self::assertCount(self::TEMPLATES, $by_template);
    // Half the Views use a template, with two block displays each; the
    // default display inherits nothing, so it is not listed.
    self::assertSame(self::VIEWS, \array_sum(\array_map('count', $by_template)));

    // The delete check runs the scan once per template.
    $this->assertWithin(3.0, function () use ($usage): void {
      for ($t = 0; $t < self::TEMPLATES; $t++) {
        $usage->displaysUsing('t' . $t);
      }
    }, 'Checking every template for delete');
  }

  /**
   * Hiding what an account may not see stays fast and does not hold every View.
   */
  public function testAccessChecksForAnAccountWithoutAdminRights(): void {
    $usage = $this->container->get(ViewTemplateUsage::class);
    $entries = \array_merge(...\array_values($usage->displaysByTemplate()));
    $visible = [];
    $memory = $this->assertWithin(3.0, function () use ($usage, $entries, &$visible): void {
      $visible = $usage->visibleTo($entries, $this->viewer);
    }, 'Checking access to every display');
    self::assertCount(\count($entries), $visible);
    // About 3.5 MB at 500 Views, mostly the Views built to check them. This
    // catches a change that keeps much more, not a small drift.
    self::assertLessThan(16 * 1024 * 1024, $memory, 'Access checks held too much memory.');
  }

  /**
   * The "View field" choices from every View, for both kinds of account.
   */
  public function testFieldChoicesFromEveryView(): void {
    foreach ([$this->admin, $this->viewer] as $account) {
      $this->setCurrentUser($account);
      $choices = new ViewFieldChoices(
        $this->container->get(ViewTemplatePreviewSelection::class),
        $this->container->get(ViewTemplateUsage::class),
        $this->container->get('config.factory'),
        $account,
      );
      $result = [];
      $memory = $this->assertWithin(3.0, function () use ($choices, &$result): void {
        $result = $choices->forEntity(NULL);
      }, 'Listing every View\'s fields as uid ' . $account->id());
      // Every field of the test Views, and any the site's own Views add.
      $expected = \array_map(static fn (int $i): string => 'f' . $i, \range(0, 7));
      self::assertSame([], \array_diff($expected, \array_keys($result['options'])));
      self::assertSame(ViewFieldChoices::SOURCE_ALL, $result['source']);
      // Under 4 MB at 500 Views; the cap is for a change that keeps far more.
      self::assertLessThan(16 * 1024 * 1024, $memory, 'Listing fields held too much memory.');
    }
  }

  /**
   * The panel's list of every template with its displays.
   */
  public function testPanelListEndpoint(): void {
    $this->setCurrentUser($this->viewer);
    $controller = ViewTemplateUsageController::create($this->container);
    $response = NULL;
    $this->assertWithin(3.0, function () use ($controller, &$response): void {
      $response = $controller->all();
    }, 'The panel list');
    self::assertNotNull($response);
    $templates = \json_decode((string) $response->getContent(), TRUE)['templates'];
    self::assertCount(self::TEMPLATES, $templates);
    self::assertCount(self::VIEWS / self::TEMPLATES, $templates['t0']);
  }

  /**
   * A thousand rows render in one pass.
   */
  public function testThousandRowsRender(): void {
    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $inputs = [
      ViewTemplateField::INPUT_FIELD => 'title',
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    $field = $this->treeItem(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME, $inputs);
    $template = ViewTemplate::create(['id' => 'big', 'label' => 'Big', 'component_tree' => [$repeater, $field]]);
    $rows = 1000;
    $row_renders = [];
    $field_rows = [];
    for ($i = 0; $i < $rows; $i++) {
      $row_renders[] = ['#markup' => 'ROW_' . $i];
      $field_rows[] = ['title' => ['#markup' => 'TITLE_' . $i]];
    }
    $data = new ViewRenderData(
      rowRenders: $row_renders,
      fieldData: ['labels' => ['title' => 'Title'], 'rows' => $field_rows],
      itemUrls: \array_fill(0, $rows, '/item'),
    );
    $html = '';
    $this->assertWithin(5.0, function () use ($template, $data, &$html): void {
      $result = $this->renderWithData($template, $data);
      $html = (string) $this->renderer->renderInIsolation($result);
    }, 'Rendering 1,000 rows');
    self::assertStringContainsString('TITLE_0', $html);
    self::assertStringContainsString('TITLE_999', $html);
  }

}
