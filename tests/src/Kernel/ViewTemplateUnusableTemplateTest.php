<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests the warning for a View whose template is missing or disabled.
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateUnusableTemplateTest extends ViewTemplateKernelTestBase {

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
   * The messages logged by this module, as `[message, context]` pairs.
   *
   * @var list<array{string, array<string, mixed>}>
   */
  private array $logged = [];

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

    $test = $this;
    $this->container->get('logger.factory')->addLogger(new class ($test) implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * Constructs the logger.
       */
      public function __construct(private readonly ViewTemplateUnusableTemplateTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? '') === 'canvas_view_template') {
          $this->test->record((string) $message, $context);
        }
      }

    });
    View::create([
      'id' => 'unusable_view',
      'label' => 'Unusable view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => 'gone']],
            'row' => ['type' => 'fields'],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Records a logged message; called by the logger above.
   */
  public function record(string $message, array $context): void {
    $this->logged[] = [$message, $context];
  }

  /**
   * Renders the View, as a page would.
   */
  private function renderView(): void {
    $view = Views::getView('unusable_view');
    self::assertNotNull($view);
    $build = $view->buildRenderable('default');
    $this->renderer->renderInIsolation($build);
  }

  /**
   * A missing template is reported once per request, naming the template.
   */
  public function testMissingTemplateIsReportedOnce(): void {
    $this->renderView();
    $this->renderView();
    self::assertCount(1, $this->logged);
    self::assertSame('gone', $this->logged[0][1]['%template']);
    self::assertSame('unusable_view', $this->logged[0][1]['%view']);
  }

  /**
   * A disabled template is reported too; an enabled one is not.
   */
  public function testDisabledTemplateIsReported(): void {
    $template = ViewTemplate::create(['id' => 'gone', 'label' => 'Gone']);
    $template->save();
    $this->renderView();
    self::assertSame([], $this->logged);

    $template->setStatus(FALSE)->save();
    $this->renderView();
    self::assertCount(1, $this->logged);
  }

}
