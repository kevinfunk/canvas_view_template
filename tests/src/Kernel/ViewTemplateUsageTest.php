<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\ViewTemplateUninstallValidator;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how usage is read from the Views' raw config, and the uninstall block.
 */
#[CoversClass(ViewTemplateUsage::class)]
#[CoversClass(ViewTemplateUninstallValidator::class)]
#[Group('canvas_view_template')]
final class ViewTemplateUsageTest extends ViewTemplateKernelTestBase {

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
   * The style option of a template.
   */
  private static function style(string $template_id): array {
    return ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => $template_id]];
  }

  /**
   * A page display that sets its own style.
   *
   * @param string $path
   *   The page path.
   * @param array<string, mixed>|null $style
   *   The style option, or NULL for the default style.
   */
  private static function own(string $path, ?array $style = NULL): array {
    return [
      'path' => $path,
      'defaults' => ['style' => FALSE, 'row' => FALSE],
      'style' => $style ?? ['type' => 'default', 'options' => []],
      'row' => ['type' => 'fields'],
    ];
  }

  /**
   * Creates a View from display definitions.
   *
   * @param string $id
   *   The View id.
   * @param array<string, array<string, mixed>> $displays
   *   The `display_options` by display id; `default` is always added.
   * @param bool $status
   *   Whether the View is enabled.
   */
  private function createView(string $id, array $displays, bool $status = TRUE): void {
    $config = [];
    foreach ($displays as $display_id => $options) {
      $config[$display_id] = [
        'display_plugin' => $display_id === 'default' ? 'default' : 'page',
        'id' => $display_id,
        'display_title' => \ucfirst($display_id),
        'display_options' => $options,
      ];
    }
    View::create([
      'id' => $id,
      'label' => \ucfirst($id),
      'status' => $status,
      'base_table' => 'node_field_data',
      'display' => $config,
    ])->save();
  }

  /**
   * Displays inheriting the default's style are reported, not the default.
   */
  public function testInheritedAndOverriddenStyles(): void {
    $this->createView('mixed', [
      'default' => ['style' => self::style('first')],
      // No `defaults` key: Views inherits the default display's style.
      'page_1' => ['path' => 'one'],
      'page_2' => ['path' => 'two', 'defaults' => ['style' => TRUE, 'row' => TRUE]],
      'page_3' => self::own('three', self::style('second')),
      'page_4' => self::own('four'),
    ]);

    $usage = $this->container->get(ViewTemplateUsage::class);
    self::assertSame(['Mixed: Page_1', 'Mixed: Page_2'], \array_column($usage->displaysUsing('first'), 'label'));
    self::assertSame(['Mixed: Page_3'], \array_column($usage->displaysUsing('second'), 'label'));
    $first = $usage->displaysUsing('first')[0];
    self::assertSame(['mixed', 'page_1'], [$first['view'], $first['display']]);
  }

  /**
   * A default display nobody inherits from still counts.
   */
  public function testDefaultDisplayCountsWhenNothingInheritsIt(): void {
    $this->createView('only_default', ['default' => ['style' => self::style('first')]]);
    $this->createView('hidden_default', [
      'default' => ['style' => self::style('second')],
      'page_1' => self::own('one'),
    ]);

    $usage = $this->container->get(ViewTemplateUsage::class);
    self::assertSame(['Only_default: Default'], \array_column($usage->displaysUsing('first'), 'label'));
    self::assertSame(['Hidden_default: Default'], \array_column($usage->displaysUsing('second'), 'label'));
  }

  /**
   * A disabled View still counts: enabling it again would render the template.
   */
  public function testDisabledViewStillCounts(): void {
    $this->createView('off', [
      'default' => ['style' => self::style('first')],
      'page_1' => ['path' => 'one'],
    ], FALSE);
    self::assertSame(['Off: Page_1'], \array_column($this->container->get(ViewTemplateUsage::class)->displaysUsing('first'), 'label'));
  }

  /**
   * Views with other styles, or no template chosen, are ignored.
   */
  public function testOtherStylesAreIgnored(): void {
    $plain = ['type' => 'default', 'options' => []];
    $unset = ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => []];
    $this->createView('plain', ['default' => ['style' => $plain], 'page_1' => ['path' => 'one']]);
    $this->createView('unset', ['default' => ['style' => $unset], 'page_1' => ['path' => 'two']]);
    $this->createView('bare', ['default' => [], 'page_1' => ['path' => 'three']]);
    self::assertSame([], $this->container->get(ViewTemplateUsage::class)->displaysByTemplate());
  }

  /**
   * Uninstall is blocked while a display uses a template, and only then.
   */
  public function testUninstallValidator(): void {
    // Canvas's own uninstall validator queries Canvas pages.
    $this->installEntitySchema('canvas_page');
    $validator = $this->container->get(ViewTemplateUninstallValidator::class);
    self::assertSame([], $validator->validate('canvas_view_template'));

    ViewTemplate::create(['id' => 'first', 'label' => 'First'])->save();
    $this->createView('used', [
      'default' => ['style' => self::style('first')],
      'page_1' => ['path' => 'one'],
    ]);

    $reasons = $validator->validate('canvas_view_template');
    self::assertCount(1, $reasons);
    self::assertStringContainsString('Used: Page_1', (string) $reasons[0]);
    self::assertSame([], $validator->validate('views'));
    // The service is registered with core's uninstall validators.
    $core = $this->container->get('module_installer')->validateUninstall(['canvas_view_template']);
    self::assertStringContainsString('Used: Page_1', \implode(' ', \array_map('strval', $core['canvas_view_template'] ?? [])));

    View::load('used')->delete();
    self::assertSame([], $validator->validate('canvas_view_template'));
  }

  /**
   * A long list of displays is cut short.
   */
  public function testUninstallReasonIsShortened(): void {
    for ($i = 1; $i <= 12; $i++) {
      $this->createView('view_' . \str_pad((string) $i, 2, '0', STR_PAD_LEFT), [
        'default' => ['style' => self::style('first')],
        'page_1' => ['path' => 'p' . $i],
      ]);
    }
    $reasons = $this->container->get(ViewTemplateUninstallValidator::class)->validate('canvas_view_template');
    self::assertStringContainsString('and 2 more', (string) $reasons[0]);
  }

}
