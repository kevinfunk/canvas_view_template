<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateAtMostOneRowsConstraint;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateAtMostOneRowsConstraintValidator;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateNoNestedRepeaterConstraint;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateNoNestedRepeaterConstraintValidator;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateRepeaterContentsConstraint;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateRepeaterContentsConstraintValidator;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateTreeItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Validation;

/**
 * Tests which components a "View repeater" may hold, and malformed trees.
 */
#[CoversClass(ViewTemplateRepeaterContentsConstraintValidator::class)]
#[CoversClass(ViewTemplateNoNestedRepeaterConstraintValidator::class)]
#[CoversClass(ViewTemplateAtMostOneRowsConstraintValidator::class)]
#[CoversClass(ViewTemplateTreeItems::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRepeaterContentsTest extends ViewTemplateKernelTestBase {

  private const string REPEATER = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;

  /**
   * Each whole-view component is rejected inside a repeater, by name.
   */
  public function testWholeViewComponentsAreRejected(): void {
    foreach (ViewTemplateRepeaterContentsConstraintValidator::labels() as $component_id => $label) {
      $repeater = $this->item(self::REPEATER);
      $inside = $this->item($component_id, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
      $messages = $this->messages([$repeater, $inside]);
      self::assertContains(\sprintf('A "%s" cannot be placed inside a "View repeater": it shows the whole view, not one item. Place it beside the repeater.', $label), $messages, $component_id);

      // Beside the repeater it is fine.
      $beside = $this->item($component_id);
      self::assertSame([], $this->repeaterMessages($this->messages([$repeater, $beside])), $component_id);
    }
  }

  /**
   * The rule applies at any depth, through containers.
   */
  public function testDeepNestingIsRejected(): void {
    $repeater = $this->item(self::REPEATER);
    $condition = $this->item(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $link = $this->item(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID, $condition['uuid'], ViewTemplateCondition::SLOT_NAME);
    $empty = $this->item(ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID, $link['uuid'], ViewTemplateItemLink::SLOT_NAME);
    $pager = $this->item(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID, $empty['uuid'], ViewTemplateEmpty::SLOT_NAME);
    $messages = $this->repeaterMessages($this->messages([$repeater, $condition, $link, $empty, $pager]));
    self::assertCount(2, $messages);
    self::assertStringContainsString('"View no results"', $messages[0] . $messages[1]);
    self::assertStringContainsString('"View pager"', $messages[0] . $messages[1]);
    // Children before their parents is fine too.
    $reversed = [$pager, $empty, $link, $condition, $repeater];
    self::assertSame(
      [$pager['uuid'] => $pager['component_id'], $empty['uuid'] => $empty['component_id']],
      ViewTemplateRepeaterContentsConstraintValidator::misplacedComponents($reversed),
    );
  }

  /**
   * Per-item components are allowed inside a repeater.
   */
  public function testPerItemComponentsAreAllowed(): void {
    $repeater = $this->item(self::REPEATER);
    $condition = $this->item(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $link = $this->item(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID, $condition['uuid'], ViewTemplateCondition::SLOT_NAME);
    $item = $this->item(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID, $link['uuid'], ViewTemplateItemLink::SLOT_NAME);
    $field = $this->item(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID, $repeater['uuid'], ViewTemplateRepeater::SLOT_NAME);
    $tree = [$repeater, $condition, $link, $item, $field];
    self::assertSame([], $this->repeaterMessages($this->messages($tree)));
    self::assertSame([], ViewTemplateRepeaterContentsConstraintValidator::misplacedComponents($tree));
  }

  /**
   * Malformed items are skipped, without errors.
   */
  public function testMalformedTreesAreTolerated(): void {
    $repeater = ['uuid' => 'r', 'component_id' => self::REPEATER];
    $rows = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;
    $items = [
      'not an item',
      NULL,
      [],
      ['uuid' => NULL, 'component_id' => NULL],
      ['component_id' => $rows, 'parent_uuid' => 'r'],
      ['uuid' => 'no-component', 'parent_uuid' => 'r'],
      ['uuid' => 5, 'component_id' => $rows, 'parent_uuid' => 'r'],
      ['uuid' => 'bad-parent', 'component_id' => $rows, 'parent_uuid' => ['r']],
      // A loop that never reaches a repeater.
      ['uuid' => 'a', 'component_id' => self::REPEATER, 'parent_uuid' => 'b'],
      ['uuid' => 'b', 'component_id' => 'other.component', 'parent_uuid' => 'a'],
      $repeater,
      ['uuid' => 'ok', 'component_id' => $rows, 'parent_uuid' => 'r'],
    ];
    self::assertSame(['ok' => $rows], ViewTemplateRepeaterContentsConstraintValidator::misplacedComponents($items));
    // "b" sits inside repeater "a", but "a" also sits inside "b": a loop.
    self::assertSame(['a'], ViewTemplateNoNestedRepeaterConstraintValidator::nestedRepeaterUuids($items));

    $validator = Validation::createValidator();
    $violations = $validator->validate($items, [
      new ViewTemplateRepeaterContentsConstraint(),
      new ViewTemplateNoNestedRepeaterConstraint(),
      new ViewTemplateAtMostOneRowsConstraint(),
    ]);
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    self::assertSame([
      'A "View rows" cannot be placed inside a "View repeater": it shows the whole view, not one item. Place it beside the repeater.',
      'A "View repeater" cannot be placed inside another "View repeater". Place it beside it, or use "View item" and "View field" inside the first one.',
      'A view template must contain only one "View rows" placement, but found 2.',
    ], $messages);
    self::assertCount(0, $validator->validate(NULL, [new ViewTemplateRepeaterContentsConstraint()]));
  }

  /**
   * The messages of all violations of a template with the given tree.
   *
   * @return list<string>
   *   The messages.
   */
  private function messages(array $tree): array {
    $template = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => $tree]);
    $messages = [];
    foreach ($template->getTypedData()->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Only the messages about what a repeater holds.
   *
   * @return list<string>
   *   The messages.
   */
  private function repeaterMessages(array $messages): array {
    return \array_values(\array_filter($messages, static fn (string $message): bool => \str_contains($message, 'inside a "View repeater"')));
  }

  /**
   * Builds a tree item for a component, optionally inside a slot.
   */
  private function item(string $component_id, ?string $parent_uuid = NULL, ?string $slot = NULL): array {
    $component = Component::load($component_id);
    self::assertNotNull($component, $component_id);
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

}
