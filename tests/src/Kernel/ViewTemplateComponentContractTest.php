<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateComponentSourceBase;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks what Canvas expects of every component source this module provides.
 *
 * The components are found from config, so a new one cannot be forgotten.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateComponentContractTest extends ViewTemplateKernelTestBase {

  /**
   * How many components the module installs.
   */
  private const int COMPONENT_COUNT = 16;

  /**
   * An invalid value for one setting, by source local ID.
   */
  private const array BAD_INPUT = [
    'view_condition' => [ViewTemplateCondition::INPUT_CONDITION => 'nope'],
    'view_feed_icons' => [ViewTemplateFeedIcons::INPUT_LABEL => 'nope'],
    'view_field' => [ViewTemplateField::INPUT_ELEMENT => 'nope'],
    'view_item_link' => [ViewTemplateItemLink::INPUT_OPEN => 'nope'],
    'view_repeater' => [ViewTemplateRepeater::INPUT_LIMIT => -1],
    'view_summary' => [ViewTemplateSummary::INPUT_ELEMENT => 'nope'],
    'view_title' => [ViewTemplateTitle::INPUT_ELEMENT => 'nope'],
  ];

  /**
   * Lists the IDs of the module's components.
   *
   * @return array<string, array{string}>
   *   The component IDs, keyed by ID.
   */
  public static function componentIds(): array {
    $ids = [];
    foreach (\glob(__DIR__ . '/../../../config/install/canvas.component.*.yml') ?: [] as $file) {
      $id = \substr(\basename($file, '.yml'), \strlen('canvas.component.'));
      $ids[$id] = [$id];
    }
    return $ids;
  }

  /**
   * Every installed component has a data row, and none is missing.
   */
  public function testEveryComponentIsCovered(): void {
    self::assertCount(self::COMPONENT_COUNT, self::componentIds());
    $installed = \array_keys(\array_filter(
      Component::loadMultiple(),
      static fn (Component $component): bool => $component->get('provider') === 'canvas_view_template',
    ));
    \sort($installed);
    $covered = \array_keys(self::componentIds());
    \sort($covered);
    self::assertSame($covered, $installed);
  }

  /**
   * The stored version is the one the plugin would generate.
   */
  #[DataProvider('componentIds')]
  public function testStoredVersionMatchesGeneratedHash(string $id): void {
    $component = $this->component($id);
    self::assertSame($component->getComponentSource()->generateVersionHash(), $component->getActiveVersion());
  }

  /**
   * The client model and the stored input round-trip the default input.
   */
  #[DataProvider('componentIds')]
  public function testDefaultInputRoundTrips(string $id): void {
    $component = $this->component($id);
    $source = $this->source($component);
    $defaults = $source->getDefaultExplicitInput();
    $model = $source->inputToClientModel($defaults);
    self::assertSame($defaults, $source->clientModelToInput('some-uuid', $component, $model, NULL));
    // A newly placed instance sends an empty `resolved`.
    self::assertSame($defaults, $source->clientModelToInput('some-uuid', $component, ['resolved' => []], NULL));
  }

  /**
   * The defaults are valid, and a bad setting value is rejected.
   */
  #[DataProvider('componentIds')]
  public function testValidation(string $id): void {
    $component = $this->component($id);
    $source = $this->source($component);
    self::assertCount(0, $source->validateComponentInput($source->getDefaultExplicitInput(), 'some-uuid', NULL));

    $bad = self::BAD_INPUT[$component->get('source_local_id')] ?? NULL;
    if ($bad === NULL) {
      self::assertFalse($source->requiresExplicitInput(), 'A component with settings needs a bad value in BAD_INPUT.');
      return;
    }
    $violations = $source->validateComponentInput($bad + $source->getDefaultExplicitInput(), 'some-uuid', NULL);
    self::assertGreaterThan(0, \count($violations));
    self::assertSame(\array_key_first($bad), $violations->get(0)->getPropertyPath());
  }

  /**
   * The editor gets a render array and slot metadata.
   */
  #[DataProvider('componentIds')]
  public function testClientSideInfo(string $id): void {
    $component = $this->component($id);
    $info = $this->source($component)->getClientSideInfo($component);
    self::assertIsArray($info['build']);
    self::assertIsArray($info['metadata']);
    self::assertArrayHasKey('slots', $info['metadata']);
  }

  /**
   * The hydrated input, the defaults and the explicit-input flag agree.
   */
  #[DataProvider('componentIds')]
  public function testHydrationAgreesWithDefaults(string $id): void {
    $component = $this->component($id);
    $source = $this->source($component);
    $defaults = $source->getDefaultExplicitInput();
    self::assertSame($defaults !== [], $source->requiresExplicitInput());

    $hydrated = $source->hydrateComponent($defaults, $component->getSlotDefinitions(), []);
    if ($source->requiresExplicitInput()) {
      self::assertSame($defaults, $hydrated['settings']);
    }
    else {
      self::assertArrayNotHasKey('settings', $hydrated);
    }
    self::assertSame($component->getSlotDefinitions() !== [], isset($hydrated['slots']));
  }

  /**
   * The component depends on this module and is not broken.
   */
  #[DataProvider('componentIds')]
  public function testDependenciesAndHealth(string $id): void {
    $source = $this->source($this->component($id));
    self::assertContains('canvas_view_template', $source->calculateDependencies()['module'] ?? []);
    self::assertFalse($source->isBroken());
  }

  /**
   * Loads a component by ID.
   */
  private function component(string $id): Component {
    $component = Component::load($id);
    self::assertInstanceOf(Component::class, $component, $id);
    return $component;
  }

  /**
   * Gets a component's source plugin.
   */
  private function source(Component $component): ViewTemplateComponentSourceBase {
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateComponentSourceBase::class, $source);
    return $source;
  }

}
