<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the "View repeater" ComponentSource plugin.
 */
#[CoversClass(ViewTemplateRepeater::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRepeaterTest extends ViewTemplateKernelTestBase {

  /**
   * The "View repeater" Component config entity.
   */
  private Component $component;

  /**
   * The "View repeater" ComponentSource plugin instance.
   */
  private ViewTemplateRepeater $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $component_id = ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID;
    $component = Component::load($component_id);
    self::assertNotNull($component);
    $this->component = $component;
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateRepeater::class, $source);
    $this->source = $source;
  }

  /**
   * A new instance defaults to no limit, offset 0, all pages.
   */
  public function testDefaultExplicitInput(): void {
    $default = $this->source->getDefaultExplicitInput();
    // This component's input shape differs from the documented return type.
    // @phpstan-ignore staticMethod.impossibleType
    self::assertSame([
      ViewTemplateRepeater::INPUT_OFFSET => 0,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ALL,
    ], $default);
    self::assertArrayNotHasKey(ViewTemplateRepeater::INPUT_LIMIT, $default);
    self::assertArrayNotHasKey(ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE, $default);
  }

  /**
   * The slot definition ships a non-empty string example.
   *
   * Canvas needs it to treat the component as slot-bearing.
   */
  public function testSlotDefinitions(): void {
    $slots = $this->source->getSlotDefinitions();
    self::assertArrayHasKey(ViewTemplateRepeater::SLOT_NAME, $slots);
    self::assertSame([''], $slots[ViewTemplateRepeater::SLOT_NAME]['examples']);
  }

  /**
   * A new instance's client model converts to the default explicit input.
   */
  public function testClientModelToInputNewInstance(): void {
    // This component uses the simpler {resolved: [...]} client model.
    // @phpstan-ignore argument.type
    $input = $this->source->clientModelToInput('some-uuid', $this->component, ['resolved' => []], NULL);
    self::assertSame($this->source->getDefaultExplicitInput(), $input);
  }

  /**
   * An explicit limit and page condition convert, and are cast to int.
   */
  public function testClientModelToInputExplicitValues(): void {
    // @phpstan-ignore argument.type
    $input = $this->source->clientModelToInput('some-uuid', $this->component, [
      'resolved' => [
        ViewTemplateRepeater::INPUT_LIMIT => '2',
        ViewTemplateRepeater::INPUT_OFFSET => '1',
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ONLY,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => '3',
      ],
    ], NULL);
    self::assertSame([
      ViewTemplateRepeater::INPUT_OFFSET => 1,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ONLY,
      ViewTemplateRepeater::INPUT_LIMIT => 2,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => 3,
    ], $input);
  }

  /**
   * An empty-string limit or page is omitted, not stored as 0 or ''.
   */
  public function testClientModelToInputEmptyStringsOmitted(): void {
    // @phpstan-ignore argument.type
    $input = $this->source->clientModelToInput('some-uuid', $this->component, [
      'resolved' => [
        ViewTemplateRepeater::INPUT_LIMIT => '',
        ViewTemplateRepeater::INPUT_OFFSET => 0,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ALL,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => '',
      ],
    ], NULL);
    self::assertArrayNotHasKey(ViewTemplateRepeater::INPUT_LIMIT, $input);
    self::assertArrayNotHasKey(ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE, $input);
  }

  /**
   * A limit less than 1 is rejected; empty (no limit) is fine.
   */
  public function testValidateComponentInputLimit(): void {
    $violations = $this->source->validateComponentInput([
      ViewTemplateRepeater::INPUT_LIMIT => 0,
      ViewTemplateRepeater::INPUT_OFFSET => 0,
    ], 'some-uuid', NULL);
    self::assertGreaterThan(0, \count($violations));
    self::assertSame(ViewTemplateRepeater::INPUT_LIMIT, $violations->get(0)->getPropertyPath());

    $violations = $this->source->validateComponentInput([
      ViewTemplateRepeater::INPUT_OFFSET => 0,
    ], 'some-uuid', NULL);
    self::assertCount(0, $violations);
  }

  /**
   * A negative offset is rejected.
   */
  public function testValidateComponentInputOffset(): void {
    $violations = $this->source->validateComponentInput([
      ViewTemplateRepeater::INPUT_OFFSET => -1,
    ], 'some-uuid', NULL);
    self::assertGreaterThan(0, \count($violations));
    self::assertSame(ViewTemplateRepeater::INPUT_OFFSET, $violations->get(0)->getPropertyPath());
  }

  /**
   * The "only"/"except" modes require a page number; "all" does not.
   */
  public function testValidateComponentInputPageCondition(): void {
    foreach ([ViewTemplateRepeater::PAGE_CONDITION_ONLY, ViewTemplateRepeater::PAGE_CONDITION_EXCEPT] as $mode) {
      $violations = $this->source->validateComponentInput([
        ViewTemplateRepeater::INPUT_OFFSET => 0,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => $mode,
      ], 'some-uuid', NULL);
      self::assertGreaterThan(0, \count($violations), "mode=$mode with no page number is invalid");
      self::assertSame(ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE, $violations->get(0)->getPropertyPath());

      $violations = $this->source->validateComponentInput([
        ViewTemplateRepeater::INPUT_OFFSET => 0,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => $mode,
        ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE => 1,
      ], 'some-uuid', NULL);
      self::assertCount(0, $violations, "mode=$mode with a page number is valid");
    }

    $violations = $this->source->validateComponentInput([
      ViewTemplateRepeater::INPUT_OFFSET => 0,
      ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE => ViewTemplateRepeater::PAGE_CONDITION_ALL,
    ], 'some-uuid', NULL);
    self::assertCount(0, $violations);
  }

  /**
   * No form element may override its own `#name`.
   *
   * The editor's client-side form submission ignores a custom `#name`, so
   * the value would never be saved.
   */
  public function testBuildComponentInstanceFormNeverOverridesFieldNames(): void {
    $form = [];
    $form_state = new FormState();
    $built = $this->source->buildComponentInstanceForm($form, $form_state, $this->component, 'some-component-instance-uuid', []);
    foreach ($built as $key => $element) {
      self::assertArrayNotHasKey('#name', $element, "Form element '$key' must not override #name.");
    }
  }

}
