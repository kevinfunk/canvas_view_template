<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\canvas_view_template\Update\ComponentUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the helpers an update hook uses to change a component.
 *
 * An old site is simulated by giving a component an older active version, as
 * if the module had shipped it before a change.
 */
#[CoversClass(ComponentUpdater::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateComponentUpdaterTest extends ViewTemplateKernelTestBase {

  private const string TITLE_ID = ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID;

  private const string OLD_VERSION = '0123456789abcdef';

  /**
   * The helper under test.
   */
  private ComponentUpdater $updater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The simulated old version has a made-up hash that strict schema rejects.
    // Not a property: Drupal 12 types it, Drupal 11 does not.
    // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
    $this->strictConfigSchema = FALSE;
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->updater = $this->container->get(ComponentUpdater::class);
  }

  /**
   * An older version becomes active again as if the site had not updated.
   */
  private function rollBackTitle(): string {
    $config = $this->config('canvas.component.' . self::TITLE_ID);
    $shipped = (string) $config->get('active_version');
    $config->set('active_version', self::OLD_VERSION)->save();
    $this->container->get('entity_type.manager')->getStorage(Component::ENTITY_TYPE_ID)->resetCache();
    return $shipped;
  }

  /**
   * The shipped version is added and activated, keeping the old one.
   */
  public function testUpdateToShippedAddsAndActivatesTheVersion(): void {
    $shipped = $this->rollBackTitle();
    self::assertSame(self::OLD_VERSION, Component::load(self::TITLE_ID)->getActiveVersion());

    self::assertTrue($this->updater->updateToShipped(self::TITLE_ID));
    $component = Component::load(self::TITLE_ID);
    self::assertSame($shipped, $component->getActiveVersion());
    self::assertContains(self::OLD_VERSION, $component->getVersions());
    self::assertSame($shipped, $component->getComponentSource()->generateVersionHash());

    // Running it again changes nothing.
    self::assertFalse($this->updater->updateToShipped(self::TITLE_ID));
    self::assertSame($shipped, Component::load(self::TITLE_ID)->getActiveVersion());
  }

  /**
   * A component the site lacks is installed.
   */
  public function testMissingComponentIsInstalled(): void {
    $id = ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID;
    Component::load($id)->delete();
    self::assertNull(Component::load($id));

    self::assertTrue($this->updater->updateToShipped($id));
    self::assertNotNull(Component::load($id));
  }

  /**
   * An unknown component is a mistake in the update hook, not ignored.
   */
  public function testUnknownComponentIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->updater->updateToShipped('canvas_view_template_nothing.nothing');
  }

  /**
   * Placements of the old version move, in the tree and in the draft.
   */
  public function testMigratePlacementsMovesTreesAndDrafts(): void {
    $this->rollBackTitle();
    $item = [
      'uuid' => '11111111-1111-4111-8111-111111111111',
      'component_id' => self::TITLE_ID,
      'component_version' => self::OLD_VERSION,
      'inputs' => ['level' => 'h3'],
    ];
    // A draft that differs from the published tree, or Canvas drops it.
    $extra = $this->treeItem('canvas_view_template_placeholder.view_header');
    ViewTemplate::create(['id' => 'migrated', 'label' => 'Migrated', 'component_tree' => [$item]])->save();
    $template = ViewTemplate::load('migrated');
    $draft = ViewTemplate::load('migrated');
    $draft->setComponentTree([$item, $extra]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);

    self::assertTrue($this->updater->updateToShipped(self::TITLE_ID));
    $new_version = Component::load(self::TITLE_ID)->getActiveVersion();
    $map = static fn (array $inputs): array => [ViewTemplateTitle::INPUT_ELEMENT => $inputs['level'] ?? 'h2'];
    $this->updater->migratePlacements(self::TITLE_ID, self::OLD_VERSION, $map);

    $tree = ViewTemplate::load('migrated')->getComponentTree()->getValue();
    self::assertSame($new_version, $tree[0]['component_version']);
    $inputs = ViewTemplate::load('migrated')->getComponentTree()->getComponentTreeItemByUuid($item['uuid'])->getInputs();
    self::assertSame('h3', $inputs[ViewTemplateTitle::INPUT_ELEMENT]);

    $auto_save = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($template, TRUE);
    self::assertFalse($auto_save->isEmpty(), 'The draft is kept.');
    $draft_tree = $auto_save->entity->toArray()['component_tree'];
    self::assertSame($new_version, $draft_tree[0]['component_version']);

    // A second run finds nothing left on the old version.
    $this->updater->migratePlacements(self::TITLE_ID, self::OLD_VERSION, static fn (): array => ['element' => 'h6']);
    $inputs = ViewTemplate::load('migrated')->getComponentTree()->getComponentTreeItemByUuid($item['uuid'])->getInputs();
    self::assertSame('h3', $inputs[ViewTemplateTitle::INPUT_ELEMENT]);
  }

  /**
   * Placements of a replaced component move to the new one.
   */
  public function testMovePlacementsBetweenComponents(): void {
    $header = $this->treeItem('canvas_view_template_placeholder.view_header');
    ViewTemplate::create(['id' => 'moved', 'label' => 'Moved', 'component_tree' => [$header]])->save();

    $this->updater->movePlacements('canvas_view_template_placeholder.view_header', 'canvas_view_template_placeholder.view_footer', []);

    $tree = ViewTemplate::load('moved')->getComponentTree()->getValue();
    self::assertSame('canvas_view_template_placeholder.view_footer', $tree[0]['component_id']);
    self::assertSame(Component::load('canvas_view_template_placeholder.view_footer')->getActiveVersion(), $tree[0]['component_version']);
  }

}
