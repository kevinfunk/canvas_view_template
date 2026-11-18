<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the update hooks that move placements, and uninstall cleanup.
 */
#[Group('canvas_view_template')]
final class ViewTemplateMigrationTest extends ViewTemplateKernelTestBase {

  private const string FEED_ICONS_ID = ViewTemplateFeedIcons::SOURCE_PLUGIN_ID . '.' . ViewTemplateFeedIcons::LOCAL_ID;

  private const string OLD_FEED_ICONS_ID = 'canvas_view_template_placeholder.view_feed_icons';

  private const string ITEM_LINK_ID = ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID;

  private const string OLD_ROW_LINK_ID = 'canvas_view_template_row_link.view_row_link';

  private const string EMPTY_ID = ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID;

  private const string OLD_EMPTY_ID = 'canvas_view_template_placeholder.view_empty';

  private const string ROWS_ID = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;

  private const string MOVED_UUID = '44444444-4444-4444-8444-444444444444';

  private const string DRAFT_ONLY_UUID = '55555555-5555-4555-8555-555555555555';

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
   * One tree item.
   */
  private function item(string $component_id, array $inputs = [], string $uuid = self::MOVED_UUID): array {
    return [
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => Component::load($component_id)->getActiveVersion(),
      'inputs' => $inputs,
    ];
  }

  /**
   * Saves an entity using $new_id, plus a draft, then points both at $old_id.
   *
   * The entity API refuses trees with a missing component, so the old id is
   * written into the stored config and the stored draft directly.
   */
  private function createStale(ConfigEntityInterface $entity, string $new_id, string $old_id, bool $with_draft): void {
    $entity->save();
    $store = \Drupal::keyValue('canvas.auto_save');
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $entry = NULL;
    if ($with_draft) {
      $draft = $this->reload($entity);
      $draft->set('component_tree', [
        ...\array_values($draft->get('component_tree')),
        $this->item(self::ROWS_ID, [], self::DRAFT_ONLY_UUID),
      ]);
      $this->container->get(AutoSaveManager::class)->saveEntity($draft);
      $entry = $store->get($key);
      self::assertIsArray($entry);
    }

    $config = $this->config($entity->getConfigDependencyName());
    $config->setData(self::replaceInStrings($config->getRawData(), $new_id, $old_id))->save();
    $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId())->resetCache();

    // Saving a Canvas config entity's tree drops its draft, so store it after.
    if ($entry !== NULL) {
      $store->set($key, self::replaceInStrings($entry, $new_id, $old_id));
    }
  }

  /**
   * Replaces a substring in every string of a nested array.
   */
  private static function replaceInStrings(array $data, string $search, string $replace): array {
    \array_walk_recursive($data, static function (mixed &$value) use ($search, $replace): void {
      if (\is_string($value)) {
        $value = \str_replace($search, $replace, $value);
      }
    });
    return $data;
  }

  /**
   * Loads an entity fresh from storage.
   */
  private function reload(EntityInterface $entity): EntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId());
    $storage->resetCache();
    return $storage->load($entity->id());
  }

  /**
   * The published tree of an entity, keyed by component instance UUID.
   */
  private function publishedTree(EntityInterface $entity): array {
    return \array_column($this->reload($entity)->toArray()['component_tree'], NULL, 'uuid');
  }

  /**
   * The draft tree of an entity, keyed by component instance UUID.
   */
  private function draftTree(EntityInterface $entity): array {
    $draft = $this->container->get(AutoSaveManager::class)->getAutoSaveEntity($this->reload($entity), TRUE);
    self::assertFalse($draft->isEmpty(), 'The draft is kept.');
    return \array_column($draft->entity->toArray()['component_tree'], NULL, 'uuid');
  }

  /**
   * Asserts a tree item points at a component's active version.
   */
  private static function assertMoved(array $item, string $component_id, array $inputs): void {
    self::assertSame($component_id, $item['component_id']);
    self::assertSame(Component::load($component_id)->getActiveVersion(), $item['component_version']);
    self::assertSame($inputs, $item['inputs']);
  }

  /**
   * The old "View feed icons" placeholder becomes the component, label shown.
   */
  public function testFeedIconsPlaceholderIsMoved(): void {
    $template = ViewTemplate::create([
      'id' => 'feed',
      'label' => 'Feed',
      'component_tree' => [$this->item(self::FEED_ICONS_ID)],
    ]);
    $this->createStale($template, self::FEED_ICONS_ID, self::OLD_FEED_ICONS_ID, TRUE);

    \canvas_view_template_update_10013();

    $expected = [ViewTemplateFeedIcons::INPUT_LABEL => ViewTemplateFeedIcons::LABEL_VISIBLE];
    self::assertMoved($this->publishedTree($template)[self::MOVED_UUID], self::FEED_ICONS_ID, $expected);
    $draft = $this->draftTree($template);
    self::assertMoved($draft[self::MOVED_UUID], self::FEED_ICONS_ID, $expected);
    self::assertSame(self::ROWS_ID, $draft[self::DRAFT_ONLY_UUID]['component_id']);
  }

  /**
   * The old "View row link" becomes "View item link", keeping "Open in".
   */
  public function testRowLinkIsMovedAndRemoved(): void {
    $open = [ViewTemplateItemLink::INPUT_OPEN => ViewTemplateItemLink::OPEN_NEW];
    $template = ViewTemplate::create([
      'id' => 'linked',
      'label' => 'Linked',
      'component_tree' => [$this->item(self::ITEM_LINK_ID, $open)],
    ]);
    $this->createStale($template, self::ITEM_LINK_ID, self::OLD_ROW_LINK_ID, TRUE);

    // Created after the template: config with a component of a missing source
    // plugin cannot be saved.
    $this->config('canvas.component.' . self::OLD_ROW_LINK_ID)->setData([
      'uuid' => \Drupal::service('uuid')->generate(),
      'id' => self::OLD_ROW_LINK_ID,
      'label' => 'View row link',
      'source' => 'canvas_view_template_row_link',
      'source_local_id' => 'view_row_link',
    ] + $this->config('canvas.component.' . self::ITEM_LINK_ID)->getRawData())->save();
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    $folder->addItems([self::OLD_ROW_LINK_ID])->save();

    \canvas_view_template_update_10016();

    self::assertMoved($this->publishedTree($template)[self::MOVED_UUID], self::ITEM_LINK_ID, $open);
    self::assertMoved($this->draftTree($template)[self::MOVED_UUID], self::ITEM_LINK_ID, $open);
    self::assertTrue($this->config('canvas.component.' . self::OLD_ROW_LINK_ID)->isNew());
    $items = Folder::load($folder->id())->get('items');
    self::assertNotContains(self::OLD_ROW_LINK_ID, $items);
    self::assertContains(self::ITEM_LINK_ID, $items);
  }

  /**
   * Update 10003 moves placements before deleting the old component.
   *
   * Deleting it first would delete every template depending on it.
   */
  public function testEmptyPlaceholderTemplateSurvives(): void {
    $data = $this->config('canvas.component.' . self::ROWS_ID)->getRawData();
    $this->config('canvas.component.' . self::OLD_EMPTY_ID)->setData([
      'uuid' => \Drupal::service('uuid')->generate(),
      'id' => self::OLD_EMPTY_ID,
      'label' => 'View empty',
      'source_local_id' => 'view_empty',
    ] + $data)->save();

    $template = ViewTemplate::create([
      'id' => 'empty',
      'label' => 'Empty',
      'component_tree' => [$this->item(self::EMPTY_ID)],
    ]);
    // Also points the recorded dependency at the old component.
    $this->createStale($template, self::EMPTY_ID, self::OLD_EMPTY_ID, FALSE);
    self::assertContains('canvas.component.' . self::OLD_EMPTY_ID, $this->config('canvas_view_template.view_template.empty')->get('dependencies.config'));

    \canvas_view_template_update_10003();

    self::assertNull(Component::load(self::OLD_EMPTY_ID));
    self::assertNotNull(ViewTemplate::load('empty'));
    self::assertMoved($this->publishedTree($template)[self::MOVED_UUID], self::EMPTY_ID, []);
  }

  /**
   * Placements in patterns, another Canvas editor, are moved too.
   */
  public function testPatternIsMoved(): void {
    $pattern = Pattern::create([
      'id' => 'feed_pattern',
      'label' => 'Feed pattern',
      'component_tree' => [$this->item(self::FEED_ICONS_ID)],
    ]);
    $this->createStale($pattern, self::FEED_ICONS_ID, self::OLD_FEED_ICONS_ID, TRUE);

    \canvas_view_template_update_10013();

    $expected = [ViewTemplateFeedIcons::INPUT_LABEL => ViewTemplateFeedIcons::LABEL_VISIBLE];
    self::assertMoved($this->publishedTree($pattern)[self::MOVED_UUID], self::FEED_ICONS_ID, $expected);
    $draft = $this->draftTree($pattern);
    self::assertMoved($draft[self::MOVED_UUID], self::FEED_ICONS_ID, $expected);
    self::assertArrayHasKey(self::DRAFT_ONLY_UUID, $draft);
  }

  /**
   * Update 10017 removes this module's missing components from folders.
   */
  public function testMissingComponentsAreRemovedFromFolder(): void {
    $stale = 'canvas_view_template_conditional_repeater.view_conditional_repeater';
    $other = 'sdc.missing.elsewhere';
    $folder = Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID);
    $folder->addItems([$stale, $other])->save();

    \canvas_view_template_update_10017();
    \canvas_view_template_update_10017();

    $items = Folder::load($folder->id())->get('items');
    self::assertNotContains($stale, $items);
    self::assertContains($other, $items);
    self::assertContains(self::ROWS_ID, $items);
  }

  /**
   * Uninstalling leaves no components and no empty folder behind.
   */
  public function testUninstallRemovesComponentsAndFolder(): void {
    \canvas_view_template_uninstall(FALSE);

    $ids = \array_keys(Component::loadMultiple());
    self::assertSame([], \array_filter($ids, static fn (string $id): bool => \str_starts_with($id, 'canvas_view_template_')));
    self::assertNull(Folder::loadByNameAndConfigEntityTypeId(ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME, Component::ENTITY_TYPE_ID));
  }

}
