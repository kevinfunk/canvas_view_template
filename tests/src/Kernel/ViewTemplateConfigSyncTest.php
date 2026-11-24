<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that view templates and the Views using them survive config sync.
 *
 * What a site exports from staging and imports on production: the templates,
 * the Views that select them and the component config, all by config sync.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateConfigSyncTest extends ViewTemplateKernelTestBase {

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
   * Deleted templates and Views come back from a sync, and nothing drifts.
   */
  public function testExportDeleteImportRoundTrip(): void {
    $rows = $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID);
    ViewTemplate::create([
      'id' => 'synced',
      'label' => 'Synced',
      'description' => 'Exported and imported.',
      'component_tree' => [$rows],
    ])->save();
    View::create([
      'id' => 'synced_view',
      'label' => 'Synced view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'synced'],
            ],
          ],
        ],
      ],
    ])->save();

    // Export, as `drush config:export` does.
    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
    self::assertTrue($this->container->get('config.storage.sync')->exists('canvas_view_template.view_template.synced'));

    // The other site lacks the template and the View.
    View::load('synced_view')->delete();
    ViewTemplate::load('synced')->delete();
    self::assertNull(ViewTemplate::load('synced'));
    self::assertNull(View::load('synced_view'));

    // Import, as `drush config:import` does.
    $importer = $this->configImporter();
    self::assertTrue($importer->getStorageComparer()->createChangelist()->hasChanges());
    $importer->import();

    $template = $this->container->get('entity_type.manager')->getStorage(ViewTemplate::ENTITY_TYPE_ID)->load('synced');
    self::assertInstanceOf(ViewTemplate::class, $template);
    self::assertSame('Exported and imported.', $template->get('description'));
    self::assertSame([$rows['uuid']], \array_column($template->getComponentTree()->getValue(), 'uuid'));
    $view = $this->container->get('entity_type.manager')->getStorage('view')->load('synced_view');
    self::assertInstanceOf(View::class, $view);
    $display = $view->get('display')['default']['display_options']['style'];
    self::assertSame('synced', $display['options'][ViewTemplateStyle::OPTION_KEY]);
    $usage = $this->container->get(ViewTemplateUsage::class)->displaysUsing('synced');
    self::assertSame(['Synced view: Default'], \array_column($usage, 'label'));

    // Exporting again gives the same config: no change on either side.
    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
    self::assertFalse($this->configImporter()->getStorageComparer()->createChangelist()->hasChanges());
  }

  /**
   * Importing the shipped component config unchanged is accepted.
   *
   * The sync directory holds the component config of the site that exported;
   * the importer checks it against the plugins and their version hashes.
   */
  public function testComponentConfigImportsUnchanged(): void {
    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
    $importer = $this->configImporter();
    self::assertFalse($importer->getStorageComparer()->createChangelist()->hasChanges());
    $importer->import();

    $count = 0;
    foreach (Component::loadMultiple() as $component) {
      $source = $component->getComponentSource();
      if (!\str_starts_with($source->getPluginId(), 'canvas_view_template')) {
        continue;
      }
      $count++;
      self::assertSame($source->generateVersionHash(), $component->getActiveVersion(), $component->id());
    }
    self::assertSame(16, $count);
  }

}
