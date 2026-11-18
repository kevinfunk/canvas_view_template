<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the update hooks that replace old placeholder components.
 */
#[Group('canvas_view_template')]
final class ViewTemplateUpdateTest extends ViewTemplateKernelTestBase {

  private const string OLD_ID = 'canvas_view_template_placeholder.view_empty';

  private const string NEW_ID = ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID;

  /**
   * {@inheritdoc}
   *
   * Strict schema checking would refuse to write the broken state under test:
   * config that points at a component that no longer exists.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->container->get('module_handler')->loadInclude('canvas_view_template', 'install');
  }

  /**
   * One tree item for the "View no results" component, by component id.
   */
  private function item(string $component_id, string $version): array {
    return [
      'uuid' => '44444444-4444-4444-8444-444444444444',
      'component_id' => $component_id,
      'component_version' => $version,
      'inputs' => [],
    ];
  }

  /**
   * A published template pointing at the old, deleted component is repaired.
   */
  public function testPublishedTemplateIsMoved(): void {
    // Raw config: the entity API would refuse a tree with a missing component.
    $this->config('canvas_view_template.view_template.stale')->setData([
      'uuid' => \Drupal::service('uuid')->generate(),
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'stale',
      'label' => 'Stale',
      'description' => NULL,
      'component_tree' => ['0' => $this->item(self::OLD_ID, 'deadbeefdeadbeef')],
    ])->save();

    \canvas_view_template_update_10004();

    $items = \array_values(ViewTemplate::load('stale')->toArray()['component_tree']);
    self::assertCount(1, $items);
    self::assertSame(self::NEW_ID, $items[0]['component_id']);
    self::assertSame(Component::load(self::NEW_ID)->getActiveVersion(), $items[0]['component_version']);
  }

  /**
   * An unpublished draft pointing at the old component is repaired too.
   */
  public function testDraftIsMoved(): void {
    $new_version = Component::load(self::NEW_ID)->getActiveVersion();
    $template = ViewTemplate::create(['id' => 'drafted', 'label' => 'Drafted']);
    $template->save();
    $manager = $this->container->get(AutoSaveManager::class);
    $draft = ViewTemplate::load('drafted');
    $draft->set('component_tree', [$this->item(self::NEW_ID, $new_version)]);
    $manager->saveEntity($draft);

    // Point the stored draft at the old component.
    $store = \Drupal::keyValue('canvas.auto_save');
    $entry = $store->get('view_template:drafted');
    self::assertIsArray($entry);
    \array_walk_recursive($entry, static function (mixed &$value): void {
      if ($value === self::NEW_ID) {
        $value = self::OLD_ID;
      }
    });
    $store->set('view_template:drafted', $entry);
    self::assertStringContainsString(self::OLD_ID, \serialize($store->get('view_template:drafted')));

    \canvas_view_template_update_10004();

    $repaired = $manager->getAutoSaveEntity(ViewTemplate::load('drafted'), TRUE);
    self::assertFalse($repaired->isEmpty());
    $tree = $repaired->entity->toArray()['component_tree'];
    self::assertSame(self::NEW_ID, \array_values($tree)[0]['component_id']);
    self::assertStringNotContainsString(self::OLD_ID, \serialize($store->get('view_template:drafted')));
  }

  /**
   * The old "View title" placeholder becomes the new component, as an h2.
   */
  public function testTitlePlaceholderBecomesTitleComponentSetToH2(): void {
    $this->config('canvas_view_template.view_template.titled')->setData([
      'uuid' => \Drupal::service('uuid')->generate(),
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'titled',
      'label' => 'Titled',
      'description' => NULL,
      'component_tree' => ['0' => $this->item('canvas_view_template_placeholder.view_title', 'deadbeefdeadbeef')],
    ])->save();

    \canvas_view_template_update_10005();

    $items = \array_values(ViewTemplate::load('titled')->toArray()['component_tree']);
    self::assertSame('canvas_view_template_title.view_title', $items[0]['component_id']);
    self::assertSame(['element' => 'h2'], $items[0]['inputs']);
  }

  /**
   * Templates that don't use the old component are left alone.
   */
  public function testOtherTemplatesAreUntouched(): void {
    $rows_id = 'canvas_view_template_placeholder.view_rows';
    $rows = $this->item($rows_id, Component::load($rows_id)->getActiveVersion());
    ViewTemplate::create(['id' => 'fine', 'label' => 'Fine', 'component_tree' => [$rows]])->save();
    $draft = ViewTemplate::load('fine');
    $other = $this->item(self::NEW_ID, Component::load(self::NEW_ID)->getActiveVersion());
    $other['uuid'] = '55555555-5555-4555-8555-555555555555';
    $draft->set('component_tree', [$rows, $other]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    $published = $this->config('canvas_view_template.view_template.fine')->getRawData();
    $store = \Drupal::keyValue('canvas.auto_save');
    $stored_draft = $store->get('view_template:fine');
    self::assertIsArray($stored_draft);

    \canvas_view_template_update_10004();

    self::assertSame($published, $this->config('canvas_view_template.view_template.fine')->getRawData());
    self::assertSame($stored_draft, $store->get('view_template:fine'));
  }

}
