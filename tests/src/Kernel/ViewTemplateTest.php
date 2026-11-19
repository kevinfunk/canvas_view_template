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
 * Tests the `canvas_view_template` config entity type.
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
      'component_tree' => [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
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
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
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
    $first = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $second = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $second],
    ]);
    self::assertEntityIsValid($view_template);

    $message = 'A "View repeater" cannot be placed inside another "View repeater". Place it beside it, or use "View item" and "View field" inside the first one.';

    $inner = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $inner],
    ]);
    self::assertContains($message, self::violationsToArray($view_template->getTypedData()->validate()));

    // With a "View condition" in between.
    $condition = $this->treeItem(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $inner = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID, $condition['uuid'], ViewTemplateCondition::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $condition, $inner],
    ]);
    self::assertContains($message, self::violationsToArray($view_template->getTypedData()->validate()));

    // A repeater beside a condition that holds "View item" is fine.
    $item = $this->treeItem(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID, $first['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$first, $item, $second],
    ]);
    self::assertEntityIsValid($view_template);
  }

}
