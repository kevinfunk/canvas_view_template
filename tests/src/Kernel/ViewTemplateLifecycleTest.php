<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests installing, uninstalling and reinstalling the module on a live site.
 *
 * The module starts out disabled, next to Canvas content and a View that
 * already exist. Installing must add its own config and leave the rest alone;
 * uninstalling must remove only what it added.
 */
#[Group('canvas_view_template')]
final class ViewTemplateLifecycleTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // Lifts Canvas's schema list of component sources, as the other tests do.
    'canvas_dev_mode',
    'node',
    'views',
  ];

  /**
   * The marker component the site's own page template uses.
   */
  private const string MARKER_VERSION = '3b12c0b99a6caecc';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Canvas's own uninstall validator queries Canvas pages.
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('user');
    // Uninstalling a module clears its rows in this table.
    $this->installSchema('user', ['users_data']);
  }

  /**
   * The component IDs this module owns.
   *
   * @return list<string>
   *   Sorted IDs.
   */
  private static function ownComponentIds(): array {
    $ids = \array_filter(
      \array_keys(Component::loadMultiple()),
      static fn (string $id): bool => \str_starts_with($id, 'canvas_view_template'),
    );
    \sort($ids);
    return $ids;
  }

  /**
   * Creates what a site already has: a page template, and a plain View.
   *
   * @return array{variant: array<string, mixed>, view: array<string, mixed>, components: array<string, array<string, mixed>>}
   *   The stored data, to compare after the module changes.
   */
  private function createExistingSiteContent(): array {
    if (Component::load(Marker::PAGE_CONTENT_COMPONENT_ID) === NULL) {
      Component::create([
        'id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'label' => 'Page content',
        'provider' => 'canvas',
        'source' => Marker::SOURCE_PLUGIN_ID,
        'source_local_id' => Marker::PAGE_CONTENT_LOCAL_ID,
        'active_version' => self::MARKER_VERSION,
        'versioned_properties' => [
          'active' => ['settings' => [], 'fallback_metadata' => ['slot_definitions' => []]],
        ],
        'dependencies' => ['enforced' => ['module' => ['canvas']]],
      ])->save();
    }
    PageVariant::create([
      'id' => 'existing_variant',
      'label' => 'Existing variant',
      'component_tree' => [
        [
          'uuid' => \Drupal::service('uuid')->generate(),
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => self::MARKER_VERSION,
          'inputs' => [],
        ],
      ],
    ])->save();
    View::create([
      'id' => 'existing_view',
      'label' => 'Existing view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => ['style' => ['type' => 'default', 'options' => []], 'row' => ['type' => 'fields']],
        ],
      ],
    ])->save();
    return [
      'variant' => PageVariant::load('existing_variant')->toArray(),
      'view' => View::load('existing_view')->toArray(),
      'components' => \array_map(static fn (Component $component): array => $component->toArray(), Component::loadMultiple()),
    ];
  }

  /**
   * Asserts the site's own config is exactly as it was.
   *
   * Canvas may add components of its own when any module is installed, so the
   * check is that nothing that existed has changed or gone, not that nothing
   * was added.
   *
   * @param array{variant: array<string, mixed>, view: array<string, mixed>, components: array<string, array<string, mixed>>} $before
   *   What createExistingSiteContent() returned.
   */
  private function assertExistingContentUnchanged(array $before): void {
    self::assertSame($before['variant'], PageVariant::load('existing_variant')->toArray());
    self::assertSame($before['view'], View::load('existing_view')->toArray());
    foreach ($before['components'] as $id => $data) {
      $component = Component::load($id);
      self::assertNotNull($component, $id . ' is gone.');
      self::assertSame($data, $component->toArray(), $id . ' changed.');
    }
  }

  /**
   * Installs the module.
   */
  private function installModule(): void {
    \Drupal::service('module_installer')->install(['canvas_view_template']);
  }

  /**
   * Uninstalls the module.
   */
  private function uninstallModule(): void {
    \Drupal::service('module_installer')->uninstall(['canvas_view_template']);
  }

  /**
   * Installing adds the 16 components and a folder, and changes nothing else.
   */
  public function testInstallOnSiteWithExistingContent(): void {
    $before = $this->createExistingSiteContent();
    self::assertSame([], self::ownComponentIds());

    $this->installModule();

    $ids = self::ownComponentIds();
    self::assertCount(16, $ids);
    // Every component's stored version is the one Canvas computes.
    foreach ($ids as $id) {
      $component = Component::load($id);
      self::assertNotNull($component, $id);
      self::assertSame($component->getComponentSource()->generateVersionHash(), $component->getActiveVersion(), $id);
    }
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    self::assertNotNull($folder);
    $items = $folder->get('items');
    \sort($items);
    self::assertSame($ids, $items);

    // The site's own config is untouched.
    $this->assertExistingContentUnchanged($before);
    // The existing View still renders on its own.
    $view = Views::getView('existing_view');
    self::assertNotNull($view);
    $build = $view->buildRenderable('default', [], FALSE);
    self::assertIsArray($build);
  }

  /**
   * A template can be made and rendered right after installing.
   */
  public function testNewTemplateWorksRightAfterInstall(): void {
    $this->createExistingSiteContent();
    $this->installModule();

    $component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID);
    self::assertNotNull($component);
    $template = ViewTemplate::create([
      'id' => 'fresh',
      'label' => 'Fresh',
      'component_tree' => [
        [
          'uuid' => \Drupal::service('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ]);
    $template->save();
    $build = \Drupal::service(ViewTemplateRenderer::class)->render($template, new ViewRenderData(rows: ['#markup' => 'THE_ROWS']));
    self::assertStringContainsString('THE_ROWS', (string) \Drupal::service('renderer')->renderInIsolation($build));
  }

  /**
   * Uninstalling removes what the module added, and only that.
   */
  public function testUninstallRemovesOnlyItsOwnConfig(): void {
    $before = $this->createExistingSiteContent();
    $this->installModule();
    ViewTemplate::create(['id' => 'goes_away', 'label' => 'Goes away'])->save();
    self::assertCount(16, self::ownComponentIds());

    $this->uninstallModule();

    self::assertSame([], self::ownComponentIds());
    self::assertNull(Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID));
    self::assertTrue(\Drupal::config('canvas_view_template.view_template.goes_away')->isNew(), 'The template is gone.');
    $this->assertExistingContentUnchanged($before);
  }

  /**
   * A folder that also holds someone else's component is kept.
   */
  public function testUninstallKeepsFolderThatHoldsOtherComponents(): void {
    $this->createExistingSiteContent();
    $this->installModule();
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    self::assertNotNull($folder);
    // Canvas allows a component in one folder only, so move one over from a
    // folder that has another item left.
    $other_id = NULL;
    foreach (Folder::loadMultiple() as $other) {
      $other_items = \array_values($other->get('items'));
      if ($other->id() !== $folder->id() && \count($other_items) >= 2) {
        $other_id = \array_pop($other_items);
        $other->set('items', $other_items)->save();
        break;
      }
    }
    self::assertNotNull($other_id, 'No other folder has a component to move.');
    $folder->set('items', [...$folder->get('items'), $other_id])->save();

    $this->uninstallModule();

    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    self::assertNotNull($folder, 'The folder holds another component, so it stays.');
    self::assertSame([$other_id], \array_values($folder->get('items')));
    self::assertSame([], self::ownComponentIds());
  }

  /**
   * A config import does not delete the components: the import supplies them.
   */
  public function testUninstallDuringConfigSyncKeepsComponents(): void {
    $this->installModule();
    \Drupal::moduleHandler()->loadInclude('canvas_view_template', 'install');
    \canvas_view_template_uninstall(TRUE);
    self::assertCount(16, self::ownComponentIds());
  }

  /**
   * Installing again after an uninstall brings everything back.
   */
  public function testReinstallRestoresEverything(): void {
    $this->createExistingSiteContent();
    $this->installModule();
    $first = self::ownComponentIds();
    $this->uninstallModule();
    self::assertSame([], self::ownComponentIds());

    $this->installModule();

    self::assertSame($first, self::ownComponentIds());
    foreach (self::ownComponentIds() as $id) {
      $component = Component::load($id);
      self::assertNotNull($component, $id);
      self::assertSame($component->getComponentSource()->generateVersionHash(), $component->getActiveVersion(), $id);
    }
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    self::assertNotNull($folder);
    self::assertCount(16, $folder->get('items'));
  }

}
