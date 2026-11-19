<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
   * A negative limit is rejected; 0 and empty both mean no limit.
   */
  public function testValidateComponentInputLimit(): void {
    $violations = $this->source->validateComponentInput([
      ViewTemplateRepeater::INPUT_LIMIT => -1,
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
   * Values that are not whole numbers, or a made-up mode, are rejected.
   *
   * @param array<string, mixed> $inputs
   *   The settings.
   * @param string $key
   *   The setting that must be reported.
   */
  #[DataProvider('wrongTypes')]
  public function testValidateComponentInputRejectsWrongTypes(array $inputs, string $key): void {
    $violations = $this->source->validateComponentInput($inputs, 'some-uuid', NULL);
    self::assertGreaterThan(0, \count($violations));
    self::assertSame($key, $violations->get(0)->getPropertyPath());
  }

  /**
   * Settings that are stored with the wrong type, and where it shows.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   *   The cases.
   */
  public static function wrongTypes(): array {
    $limit = ViewTemplateRepeater::INPUT_LIMIT;
    $offset = ViewTemplateRepeater::INPUT_OFFSET;
    $mode = ViewTemplateRepeater::INPUT_PAGE_CONDITION_MODE;
    $page = ViewTemplateRepeater::INPUT_PAGE_CONDITION_PAGE;
    return [
      'limit as a string' => [[$limit => '2', $offset => 0], $limit],
      'limit as a fraction' => [[$limit => 1.5, $offset => 0], $limit],
      'offset as a string' => [[$offset => '2'], $offset],
      'offset as a boolean' => [[$offset => TRUE], $offset],
      'mode that does not exist' => [[$offset => 0, $mode => 'bogus'], $mode],
      'mode as an array' => [[$offset => 0, $mode => ['only']], $mode],
      'page as a string' => [[$offset => 0, $mode => 'only', $page => '1'], $page],
    ];
  }

  /**
   * Stored settings are read as safe values, whatever their type.
   *
   * @param mixed $stored
   *   What was stored.
   * @param array<string, mixed> $expected
   *   The safe values.
   */
  #[DataProvider('storedSettings')]
  public function testNormalizeSettings(mixed $stored, array $expected): void {
    self::assertSame($expected, ViewTemplateRepeater::normalizeSettings($stored));
  }

  /**
   * Stored settings and the safe values read from them.
   *
   * @return array<string, array{0: mixed, 1: array<string, mixed>}>
   *   The cases.
   */
  public static function storedSettings(): array {
    $none = ['limit' => NULL, 'offset' => 0, 'mode' => 'all', 'page' => NULL];
    return [
      'nothing stored' => [[], $none],
      'not an array' => ['junk', $none],
      'whole numbers' => [
        ['limit' => 3, 'offset' => 2, 'page_condition_mode' => 'only', 'page_condition_page' => 2],
        ['limit' => 3, 'offset' => 2, 'mode' => 'only', 'page' => 2],
      ],
      'numbers as strings' => [
        ['limit' => ' 3 ', 'offset' => '2', 'page_condition_mode' => 'except', 'page_condition_page' => '4'],
        ['limit' => 3, 'offset' => 2, 'mode' => 'except', 'page' => 4],
      ],
      'whole floats' => [
        ['limit' => 3.0, 'offset' => 1.0],
        ['limit' => 3, 'offset' => 1, 'mode' => 'all', 'page' => NULL],
      ],
      'zero limit means no limit' => [['limit' => 0], $none],
      'negative values' => [['limit' => -2, 'offset' => -5, 'page_condition_page' => -1], $none],
      'junk values' => [
        ['limit' => 'abc', 'offset' => [1], 'page_condition_mode' => 'bogus', 'page_condition_page' => '1.5'],
        $none,
      ],
      'page zero' => [
        ['page_condition_mode' => 'only', 'page_condition_page' => 0],
        \array_replace($none, ['mode' => 'only']),
      ],
    ];
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
