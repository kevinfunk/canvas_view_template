<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Config\TypedConfigManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * Checks the module on a site without `canvas_dev_mode`, as production runs.
 *
 * Canvas's `canvas.component.*` schema only allows its own component source
 * plugin IDs, unless `canvas_dev_mode` lifts that (drupal.org/i/3520484). Every
 * other test here enables dev mode, so this one records what real sites get.
 * If a Canvas update changes these results, the README's "Coupling to Canvas"
 * needs revisiting.
 */
#[Group('canvas_view_template')]
final class ViewTemplateWithoutDevModeTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Strict schema checking would reject the config at install, which is the
   * behaviour under test, so it is off here.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_view_template',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_view_template']);
  }

  /**
   * The module's config installs, and dev mode is really absent.
   */
  public function testInstallsWithoutDevMode(): void {
    self::assertFalse($this->container->get('module_handler')->moduleExists('canvas_dev_mode'));
    $ids = \array_filter(
      \array_keys(Component::loadMultiple()),
      static fn (string $id): bool => \str_starts_with($id, 'canvas_view_template'),
    );
    self::assertCount(16, $ids);
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    self::assertNotNull($folder);
    self::assertCount(16, $folder->get('items'));
  }

  /**
   * A template renders its placeholder with the view's output.
   */
  public function testRendersWithoutDevMode(): void {
    $component_id = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $template = ViewTemplate::create([
      'id' => 'without_dev_mode',
      'label' => 'Without dev mode',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component_id,
          'component_version' => $component->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ]);
    $build = $this->container->get(ViewTemplateRenderer::class)->render($template, new ViewRenderData(rows: ['#markup' => 'THE_ROWS']));
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    self::assertStringContainsString('THE_ROWS', $html);
  }

  /**
   * Schema validation flags only the `source` choice, on every component.
   *
   * This is the documented gap: nothing else is wrong with the shipped config.
   */
  public function testSchemaViolationsAreOnlyTheSourceChoice(): void {
    $typed = $this->container->get(TypedConfigManagerInterface::class);
    $found = [];
    foreach (Component::loadMultiple() as $id => $component) {
      if (!\str_starts_with($id, 'canvas_view_template')) {
        continue;
      }
      $name = 'canvas.component.' . $id;
      $violations = $typed->createFromNameAndData($name, $this->config($name)->get())->validate();
      foreach ($violations as $violation) {
        $found[$id][] = $violation->getPropertyPath();
      }
    }
    $expected = \array_fill_keys(
      \array_filter(\array_keys(Component::loadMultiple()), static fn (string $id): bool => \str_starts_with($id, 'canvas_view_template')),
      ['source'],
    );
    \ksort($found);
    \ksort($expected);
    self::assertSame($expected, $found);
  }

}
