<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests "View condition": content shown only when a condition is met.
 */
#[CoversClass(ViewTemplateCondition::class)]
#[CoversClass(ViewConditions::class)]
#[Group('canvas_view_template')]
final class ViewTemplateConditionTest extends ViewTemplateKernelTestBase {

  /**
   * The conditions are decided from the facts and the pieces Views built.
   *
   * @param string $condition
   *   The condition.
   * @param array<string, bool> $facts
   *   What ViewConditions::collect() would return.
   * @param array<string, mixed> $areas
   *   The pieces Views built.
   * @param bool $expected
   *   Whether the condition is met.
   */
  #[DataProvider('conditions')]
  public function testIsMet(string $condition, array $facts, array $areas, bool $expected): void {
    self::assertSame($expected, ViewConditions::isMet($condition, $facts, $areas));
  }

  /**
   * Data provider for ::testIsMet().
   */
  public static function conditions(): array {
    $page = ['has_results' => TRUE, 'multiple_pages' => TRUE, 'first_page' => FALSE, 'filters_applied' => TRUE];
    $none = ['has_results' => FALSE, 'multiple_pages' => FALSE, 'first_page' => TRUE, 'filters_applied' => FALSE];
    return [
      'results' => [ViewConditions::HAS_RESULTS, $page, [], TRUE],
      'no results' => [ViewConditions::HAS_RESULTS, $none, [], FALSE],
      'several pages' => [ViewConditions::MULTIPLE_PAGES, $page, [], TRUE],
      'one page' => [ViewConditions::MULTIPLE_PAGES, $none, [], FALSE],
      'first page' => [ViewConditions::FIRST_PAGE, $none, [], TRUE],
      'not the first page' => [ViewConditions::FIRST_PAGE, $page, [], FALSE],
      'not first, on page 2' => [ViewConditions::NOT_FIRST_PAGE, $page, [], TRUE],
      'not first, on page 1' => [ViewConditions::NOT_FIRST_PAGE, $none, [], FALSE],
      'filters' => [ViewConditions::FILTERS_APPLIED, $page, [], TRUE],
      'no filters' => [ViewConditions::FILTERS_APPLIED, $none, [], FALSE],
      'header' => [ViewConditions::HAS_HEADER, $none, ['header' => ['#markup' => 'x']], TRUE],
      'no header' => [ViewConditions::HAS_HEADER, $none, ['footer' => ['#markup' => 'x']], FALSE],
      'footer' => [ViewConditions::HAS_FOOTER, $none, ['footer' => ['#markup' => 'x']], TRUE],
      'more' => [ViewConditions::HAS_MORE, $none, ['more' => ['#markup' => 'x']], TRUE],
      'no more' => [ViewConditions::HAS_MORE, $none, [], FALSE],
      'attachment before' => [ViewConditions::HAS_ATTACHMENTS, $none, ['attachment_before' => ['#markup' => 'x']], TRUE],
      'attachment after' => [ViewConditions::HAS_ATTACHMENTS, $none, ['attachment_after' => ['#markup' => 'x']], TRUE],
      'no attachments' => [ViewConditions::HAS_ATTACHMENTS, $none, [], FALSE],
      'feed' => [ViewConditions::HAS_FEED, $none, ['feed_icons' => ['#markup' => 'x']], TRUE],
      'no feed' => [ViewConditions::HAS_FEED, $none, [], FALSE],
      // Never hide content over a setting that can't be read.
      'unknown' => ['nonsense', $none, [], TRUE],
    ];
  }

  /**
   * The slot shows when the condition is met, and nothing is left when not.
   */
  public function testSlotShowsOnlyWhenMet(): void {
    [$condition, $child] = $this->conditionWithPager(ViewConditions::MULTIPLE_PAGES);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$condition, $child],
    ]);

    $facts = ['multiple_pages' => TRUE];
    $rendered = $this->renderTemplate($view_template, $facts);
    self::assertStringContainsString('THE_PAGER', $rendered);
    // The live page has no wrapper of its own.
    self::assertStringNotContainsString('cvt-marker', $rendered);

    $rendered = $this->renderTemplate($view_template, ['multiple_pages' => FALSE]);
    self::assertStringNotContainsString('THE_PAGER', $rendered);
    self::assertSame('', \trim(\strip_tags($rendered)));
  }

  /**
   * A condition that looks at a piece of the view uses the view's pieces.
   */
  public function testConditionOnViewPiece(): void {
    [$condition, $child] = $this->conditionWithPager(ViewConditions::HAS_MORE);
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$condition, $child],
    ]);
    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', ['#markup' => 'THE_PAGER'], [], 0, ['more' => ['#markup' => 'THE_MORE']]);
    self::assertStringContainsString('THE_PAGER', (string) $this->renderer->renderInIsolation($result));
    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', ['#markup' => 'THE_PAGER']);
    self::assertStringNotContainsString('THE_PAGER', (string) $this->renderer->renderInIsolation($result));
  }

  /**
   * Conditions inside a repeater are decided too.
   */
  public function testConditionInsideRepeater(): void {
    $repeater_component = Component::load(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    self::assertNotNull($repeater_component);
    $repeater_uuid = \Drupal::service('uuid')->generate();
    $repeater = [
      'uuid' => $repeater_uuid,
      'component_id' => $repeater_component->id(),
      'component_version' => $repeater_component->getActiveVersion(),
      'inputs' => [ViewTemplateRepeater::INPUT_OFFSET => 0],
    ];
    $condition_component = Component::load(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID);
    self::assertNotNull($condition_component);
    $condition_uuid = \Drupal::service('uuid')->generate();
    $condition = [
      'uuid' => $condition_uuid,
      'component_id' => $condition_component->id(),
      'component_version' => $condition_component->getActiveVersion(),
      'inputs' => [ViewTemplateCondition::INPUT_CONDITION => ViewConditions::HAS_HEADER],
      'parent_uuid' => $repeater_uuid,
      'slot' => ViewTemplateRepeater::SLOT_NAME,
    ];
    $item_component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID);
    self::assertNotNull($item_component);
    $item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $item_component->id(),
      'component_version' => $item_component->getActiveVersion(),
      'inputs' => [],
      'parent_uuid' => $condition_uuid,
      'slot' => ViewTemplateCondition::SLOT_NAME,
    ];
    $view_template = ViewTemplate::create([
      'id' => 'test',
      'label' => 'Test',
      'component_tree' => [$repeater, $condition, $item],
    ]);
    $renderer = $this->container->get(ViewTemplateRenderer::class);

    $result = $renderer->render($view_template, '', '', '', ['ROW_A', 'ROW_B'], 0, ['header' => ['#markup' => 'H']]);
    $rendered = (string) $this->renderer->renderInIsolation($result);
    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);

    $result = $renderer->render($view_template, '', '', '', ['ROW_A', 'ROW_B'], 0, []);
    $rendered = (string) $this->renderer->renderInIsolation($result);
    self::assertStringNotContainsString('ROW_A', $rendered);
    self::assertStringNotContainsString('ROW_B', $rendered);
  }

  /**
   * The setting is a select with every condition, and rejects anything else.
   */
  public function testSettingsForm(): void {
    $component = Component::load(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateCondition::class, $source);

    $form = $source->buildComponentInstanceForm([], new FormState(), $component, '', [ViewTemplateCondition::INPUT_CONDITION => ViewConditions::FILTERS_APPLIED]);
    $field = $form[ViewTemplateCondition::INPUT_CONDITION];
    self::assertSame('select', $field['#type']);
    self::assertSame(\array_keys(ViewConditions::options()), \array_keys($field['#options']));
    self::assertSame(ViewConditions::FILTERS_APPLIED, $field['#default_value']);

    // A bad stored value falls back to the default.
    $form = $source->buildComponentInstanceForm([], new FormState(), $component, '', [ViewTemplateCondition::INPUT_CONDITION => 'nonsense']);
    self::assertSame(ViewConditions::DEFAULT, $form[ViewTemplateCondition::INPUT_CONDITION]['#default_value']);

    self::assertCount(0, $source->validateComponentInput([ViewTemplateCondition::INPUT_CONDITION => ViewConditions::HAS_FEED], '', NULL));
    self::assertCount(1, $source->validateComponentInput([ViewTemplateCondition::INPUT_CONDITION => 'nonsense'], '', NULL));
  }

  /**
   * Renders a template with a pager of "THE_PAGER" and the given facts.
   *
   * @param \Drupal\canvas_view_template\Entity\ViewTemplate $view_template
   *   The template.
   * @param array<string, bool> $facts
   *   The facts about the view.
   */
  private function renderTemplate(ViewTemplate $view_template, array $facts): string {
    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, '', '', ['#markup' => 'THE_PAGER'], [], 0, [], [], [], $facts);
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * Builds a "View condition" with a "View pager" in its slot.
   *
   * @return array{0: array, 1: array}
   *   The condition's tree item and its child's.
   */
  private function conditionWithPager(string $condition): array {
    $condition_component = Component::load(ViewTemplateCondition::SOURCE_PLUGIN_ID . '.' . ViewTemplateCondition::LOCAL_ID);
    self::assertNotNull($condition_component);
    $uuid = \Drupal::service('uuid')->generate();
    $pager = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID);
    self::assertNotNull($pager);
    return [
      [
        'uuid' => $uuid,
        'component_id' => $condition_component->id(),
        'component_version' => $condition_component->getActiveVersion(),
        'inputs' => [ViewTemplateCondition::INPUT_CONDITION => $condition],
      ],
      [
        'uuid' => \Drupal::service('uuid')->generate(),
        'component_id' => $pager->id(),
        'component_version' => $pager->getActiveVersion(),
        'inputs' => [],
        'parent_uuid' => $uuid,
        'slot' => ViewTemplateCondition::SLOT_NAME,
      ],
    ];
  }

}
