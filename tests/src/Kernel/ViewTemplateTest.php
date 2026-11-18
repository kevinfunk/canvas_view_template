<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `view_template` config entity type.
 */
#[CoversClass(ViewTemplate::class)]
#[Group('canvas_view_template')]
final class ViewTemplateTest extends ViewTemplateKernelTestBase {

  /**
   * Each shipped component's `active_version` matches its settings' hash.
   *
   * The hash is hard-coded in install config, so this catches schema drift.
   */
  public function testShippedComponentVersionHashesAreCurrent(): void {
    $ids = \Drupal::service('config.storage')->listAll('canvas.component.canvas_view_template_');
    self::assertCount(16, $ids);
    foreach ($ids as $config_name) {
      $component = Component::load(\substr($config_name, \strlen('canvas.component.')));
      self::assertNotNull($component, $config_name);
      self::assertSame($component->getComponentSource()->generateVersionHash(), $component->getActiveVersion(), $config_name);
    }
  }

  /**
   * An empty tree is valid: "View rows" is optional.
   */
  public function testEmptyTreeIsValid(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
    ]);
    self::assertEntityIsValid($view_template);
  }

  /**
   * Exactly one "View rows" placement is valid.
   */
  public function testSingleViewRowsIsValid(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->viewRowsItem()],
    ]);
    self::assertEntityIsValid($view_template);
  }

  /**
   * More than one "View rows" placement is rejected.
   */
  public function testMultipleViewRowsIsInvalid(): void {
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$this->viewRowsItem(), $this->viewRowsItem()],
    ]);
    $violations = $view_template->getTypedData()->validate();
    $actual = self::violationsToArray($violations);
    self::assertNotSame([], $actual);
    self::assertContains(
      'A view template must contain only one "View rows" placement, but found 2.',
      $actual,
    );
  }

  /**
   * Repeaters side by side are fine; one inside another is rejected.
   */
  public function testNestedRepeaterIsInvalid(): void {
    $first = $this->item(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $second = $this->item(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $second],
    ]);
    self::assertEntityIsValid($view_template);

    $message = 'A "View repeater" cannot be placed inside another "View repeater". Place it beside it, or use "View item" and "View field" inside the first one.';

    $inner = $this->item(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $inner],
    ]);
    self::assertContains($message, self::violationsToArray($view_template->getTypedData()->validate()));

    // With a "View condition" in between.
    $condition = $this->item(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $inner = $this->item(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID, $condition['uuid'], ViewTemplateCondition::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $condition, $inner],
    ]);
    self::assertContains($message, self::violationsToArray($view_template->getTypedData()->validate()));

    // A repeater beside a condition that holds "View item" is fine.
    $item = $this->item(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $item, $second],
    ]);
    self::assertEntityIsValid($view_template);
  }

  /**
   * Builds a tree item for a component, optionally inside a slot.
   *
   * @param string $component_id
   *   The Component config entity id.
   * @param string|null $parent_uuid
   *   The parent's UUID, or NULL for a root-level item.
   * @param string|null $slot
   *   The parent's slot name.
   *
   * @return array
   *   The tree item. A root-level item omits `parent_uuid` and `slot`.
   */
  private function item(string $component_id, ?string $parent_uuid = NULL, ?string $slot = NULL): array {
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $component->getComponentSource()->getDefaultExplicitInput(),
    ];
    if ($parent_uuid !== NULL) {
      $item['parent_uuid'] = $parent_uuid;
      $item['slot'] = $slot;
    }
    return $item;
  }

  /**
   * Builds a root-level "View rows" placeholder tree item.
   *
   * @return array
   *   The tree item, without `parent_uuid` and `slot` (NULL fails validation).
   */
  private function viewRowsItem(): array {
    $component_id = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => [],
    ];
  }

}
