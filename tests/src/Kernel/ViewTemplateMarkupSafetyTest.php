<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that names and settings are shown as text, never as markup.
 *
 * Template and View names, and the settings stored in a template, are typed by
 * people with access to the editor. Whatever they contain must reach a page as
 * text. The payloads carry a token so a leak is easy to see in the output.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateMarkupSafetyTest extends ViewTemplateKernelTestBase {

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
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->installEntitySchema('user');
  }

  /**
   * Strings that are dangerous in some HTML context.
   *
   * @return array<string, array{0: string, 1: string}>
   *   By name: the payload, and the form of it that must never be output. For
   *   most that is the payload itself; text that is harmless where it is text
   *   (an attribute or a tag name) is only a problem as markup.
   */
  private static function payloads(): array {
    return [
      'script' => ['<script>CVTX1</script>', '<script>CVTX1</script>'],
      'image' => ['"><img src=x onerror=CVTX2>', '<img src=x onerror=CVTX2>'],
      'svg' => ['<svg/onload=CVTX3>', '<svg/onload=CVTX3>'],
      'attribute' => ['x" onmouseover="CVTX4', '" onmouseover="CVTX4'],
      'tag name' => ['div onmouseover=CVTX5', '<div onmouseover=CVTX5'],
      'closing tag' => ['</div><p class="CVTX6">', '<p class="CVTX6">'],
      'ampersand' => ['News & Events', 'News & Events'],
    ];
  }

  /**
   * The delete lock names Views as plain text, which is how the panel shows it.
   */
  public function testDeleteLockReasonIsPlainText(): void {
    ViewTemplate::create(['id' => 'locked', 'label' => 'Locked'])->save();
    $label = 'News & <b>Events</b> "all"';
    $display_title = "Block <i>x</i> & 'y'";
    $view = View::create([
      'id' => 'locking_view',
      'label' => $label,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_options' => []],
        'block_1' => [
          'display_plugin' => 'block',
          'id' => 'block_1',
          'display_title' => $display_title,
          'display_options' => [
            'defaults' => ['style' => FALSE],
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'locked'],
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('config.storage')->write('views.view.locking_view', $view->toArray());
    $this->container->get('config.factory')->reset();

    $admin = $this->createUser(['administer view templates', 'administer views'], NULL, FALSE, ['uid' => 5]);
    $template = ViewTemplate::load('locked');
    self::assertNotNull($template);
    $access = $template->access('delete', $admin, TRUE);
    self::assertTrue($access->isForbidden());
    self::assertInstanceOf(AccessResultReasonInterface::class, $access);
    $reason = $access->getReason();
    // The reason is plain text: no HTML entities for the panel to show.
    self::assertStringContainsString($label . ': ' . $display_title, $reason);
    self::assertStringNotContainsString('&amp;', $reason);
    self::assertStringNotContainsString('&lt;', $reason);
  }

  /**
   * Hostile settings never reach a preview or the page as markup.
   *
   * The hostile text goes into one setting at a time, so the others stay
   * valid: a bad field name would otherwise stop the field from showing and
   * its element would never be built.
   */
  public function testHostileSettingsAreNeverOutputAsMarkup(): void {
    $uuid = $this->container->get('uuid');
    $data = new ViewRenderData(
      rows: ['#markup' => 'ROWS'],
      rowRenders: [['#markup' => 'ROW']],
      fieldData: ['labels' => ['title' => 'Title'], 'rows' => [['title' => ['#markup' => 'VALUE']]]],
    );
    foreach (Component::loadMultiple() as $component) {
      $source = $component->getComponentSource();
      if (!\str_starts_with($source->getPluginId(), 'canvas_view_template')) {
        continue;
      }
      // Protected on the sources, so read through reflection.
      $input_name = (new \ReflectionClassConstant($source, 'EXPLICIT_INPUT_NAME'))->getValue();
      $defaults = $source->getDefaultExplicitInput();
      foreach (\array_keys($defaults) as $key) {
        foreach (self::payloads() as $name => [$payload, $forbidden]) {
          $settings = $defaults;
          if ($source instanceof ViewTemplateField) {
            $settings[ViewTemplateField::INPUT_FIELD] = 'title';
          }
          $settings[$key] = $payload;
          $context = $component->id() . ', "' . $key . '" setting, "' . $name . '" payload';

          // The editor preview.
          $build = $source->renderComponent([$input_name => $settings], [], $uuid->generate(), TRUE);
          $html = (string) $this->renderer->renderInIsolation($build);
          self::assertStringNotContainsString($forbidden, $html, 'Preview of ' . $context);

          // The page.
          $item = [
            'uuid' => $uuid->generate(),
            'component_id' => $component->id(),
            'component_version' => $component->getActiveVersion(),
            'inputs' => $settings,
          ];
          $template = ViewTemplate::create(['id' => 'hostile', 'label' => 'Hostile', 'component_tree' => [$item]]);
          $build = $this->renderWithData($template, $data);
          $html = (string) $this->renderer->renderInIsolation($build);
          self::assertStringNotContainsString($forbidden, $html, 'Page output of ' . $context);
        }
      }
    }
  }

  /**
   * Template names in the Format settings form are text.
   */
  public function testStyleFormShowsTemplateNamesAsText(): void {
    foreach (self::payloads() as $name => [$payload]) {
      ViewTemplate::create(['id' => 'named_' . \md5($name), 'label' => $payload])->save();
    }
    View::create([
      'id' => 'form_view',
      'label' => 'Form view',
      'base_table' => 'node_field_data',
      'display' => ['default' => ['display_plugin' => 'default', 'id' => 'default', 'display_options' => []]],
    ])->save();
    $view = Views::getView('form_view');
    self::assertNotNull($view);
    $view->setDisplay('default');
    $view->display_handler->setOption('style', ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => []]);
    $style = $view->display_handler->getPlugin('style');
    self::assertInstanceOf(ViewTemplateStyle::class, $style);
    $form = [];
    $style->buildOptionsForm($form, new FormState());
    $select = $form[ViewTemplateStyle::OPTION_KEY];
    $select['#name'] = 'view_template';
    $select['#parents'] = ['view_template'];
    $select['#description_display'] = 'after';
    $html = (string) $this->renderer->renderInIsolation($select);
    foreach (self::payloads() as $name => [, $forbidden]) {
      self::assertStringNotContainsString($forbidden, $html, 'A template named by a "' . $name . '" payload.');
    }
  }

}
