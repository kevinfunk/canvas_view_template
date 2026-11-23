<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateSchemaHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Config\TypedConfigManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Constraints\Choice;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * Checks the module on a site without `canvas_dev_mode`, as production runs.
 *
 * Canvas's `canvas.component.*` schema only allows its own component source
 * plugin IDs (drupal.org/i/3520484). This module adds its own to that list
 * (ViewTemplateSchemaHooks), so its components validate without dev mode,
 * which every other test here enables. If a Canvas update changes the schema,
 * this fails, and the README's "Coupling to Canvas" needs revisiting.
 */
#[Group('canvas_view_template')]
final class ViewTemplateWithoutDevModeTest extends CanvasKernelTestBase {

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
   * The shipped components pass schema validation, with dev mode absent.
   */
  public function testShippedComponentsPassSchemaValidation(): void {
    $typed = $this->container->get(TypedConfigManagerInterface::class);
    $checked = 0;
    foreach (Component::loadMultiple() as $id => $component) {
      if (!\str_starts_with($id, 'canvas_view_template')) {
        continue;
      }
      $name = 'canvas.component.' . $id;
      $violations = $typed->createFromNameAndData($name, $this->config($name)->get())->validate();
      $found = [];
      foreach ($violations as $violation) {
        $found[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      self::assertSame([], $found, $id);
      $checked++;
    }
    self::assertSame(16, $checked);
  }

  /**
   * Other sources are still refused: only this module's were added.
   */
  public function testOtherSourcesAreStillRefused(): void {
    $name = 'canvas.component.canvas_view_template_title.view_title';
    $data = $this->config($name)->get();
    $data['source'] = 'not_a_real_source';
    $violations = $this->container->get(TypedConfigManagerInterface::class)->createFromNameAndData($name, $data)->validate();
    $refused = [];
    foreach ($violations as $violation) {
      if ($violation->getPropertyPath() === 'source' && $violation->getConstraint() instanceof Choice) {
        $refused[] = $violation->getMessage();
      }
    }
    self::assertNotSame([], $refused, 'The list of allowed sources was removed, not extended.');
  }

  /**
   * The sources the hook lists are the ones the plugin manager finds.
   *
   * A source added without being listed would fail validation on a real site.
   */
  public function testEveryProvidedSourceIsListed(): void {
    $found = \array_filter(
      \array_keys($this->container->get(ComponentSourceManager::class)->getDefinitions()),
      static fn (string $id): bool => \str_starts_with($id, 'canvas_view_template'),
    );
    $listed = ViewTemplateSchemaHooks::sourcePluginIds();
    \sort($found);
    \sort($listed);
    self::assertSame($found, $listed);
  }

}
