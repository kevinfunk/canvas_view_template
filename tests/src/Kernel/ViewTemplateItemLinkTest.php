<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests "View item link": the content linked to the current item's page.
 */
#[CoversClass(ViewTemplateItemLink::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateItemLinkTest extends ViewTemplateKernelTestBase {

  /**
   * Outside a repeater, the content links to the first item.
   */
  public function testLinksToFirstItemOutsideRepeater(): void {
    [$link, $item] = $this->linkWithItem(ViewTemplateItemLink::OPEN_SAME);
    $template = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => [$link, $item]]);

    $rendered = $this->renderTemplate($template, ['ROW_A', 'ROW_B'], ['/node/1', '/node/2']);
    self::assertStringContainsString('<a href="/node/1">', $rendered);
    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringNotContainsString('/node/2', $rendered);
    self::assertStringNotContainsString('target=', $rendered);
  }

  /**
   * Opening in a new tab uses a safe link.
   */
  public function testNewTab(): void {
    [$link, $item] = $this->linkWithItem(ViewTemplateItemLink::OPEN_NEW);
    $template = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => [$link, $item]]);

    $rendered = $this->renderTemplate($template, ['ROW_A'], ['/node/1']);
    self::assertStringContainsString('target="_blank"', $rendered);
    self::assertStringContainsString('rel="noopener"', $rendered);
  }

  /**
   * An item with no page is shown, without a link.
   */
  public function testNoLinkWhenTheItemHasNoPage(): void {
    [$link, $item] = $this->linkWithItem(ViewTemplateItemLink::OPEN_SAME);
    $template = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => [$link, $item]]);

    $rendered = $this->renderTemplate($template, ['ROW_A'], [NULL]);
    self::assertStringContainsString('ROW_A', $rendered);
    self::assertStringNotContainsString('<a', $rendered);
  }

  /**
   * Inside a repeater, each repeated item links to its own page.
   */
  public function testEachRepeatedItemHasItsOwnLink(): void {
    $repeater_component = Component::load(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    self::assertNotNull($repeater_component);
    $repeater_uuid = \Drupal::service('uuid')->generate();
    $repeater = [
      'uuid' => $repeater_uuid,
      'component_id' => $repeater_component->id(),
      'component_version' => $repeater_component->getActiveVersion(),
      // Skip the first item: links must follow the rows shown.
      'inputs' => [ViewTemplateRepeater::INPUT_OFFSET => 1],
    ];
    [$link, $item] = $this->linkWithItem(ViewTemplateItemLink::OPEN_SAME, $repeater_uuid, ViewTemplateRepeater::SLOT_NAME);
    $template = ViewTemplate::create(['id' => 'test', 'label' => 'Test', 'component_tree' => [$repeater, $link, $item]]);

    $rendered = $this->renderTemplate($template, ['ROW_A', 'ROW_B', 'ROW_C'], ['/node/1', '/node/2', NULL]);
    self::assertStringNotContainsString('ROW_A', $rendered);
    self::assertStringContainsString('<a href="/node/2">', $rendered);
    self::assertStringContainsString('ROW_B', $rendered);
    self::assertStringNotContainsString('/node/1', $rendered);
    self::assertStringContainsString('ROW_C', $rendered);
    self::assertSame(1, \substr_count($rendered, '<a '));
  }

  /**
   * The setting is a select, with a bad stored value falling back.
   */
  public function testSettingsForm(): void {
    $component = Component::load(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateItemLink::class, $source);

    $form = $source->buildComponentInstanceForm([], new FormState(), $component, '', [ViewTemplateItemLink::INPUT_OPEN => ViewTemplateItemLink::OPEN_NEW]);
    $field = $form[ViewTemplateItemLink::INPUT_OPEN];
    self::assertSame('select', $field['#type']);
    self::assertSame([ViewTemplateItemLink::OPEN_SAME, ViewTemplateItemLink::OPEN_NEW], \array_keys($field['#options']));
    self::assertSame(ViewTemplateItemLink::OPEN_NEW, $field['#default_value']);

    $form = $source->buildComponentInstanceForm([], new FormState(), $component, '', [ViewTemplateItemLink::INPUT_OPEN => 'nonsense']);
    self::assertSame(ViewTemplateItemLink::OPEN_SAME, $form[ViewTemplateItemLink::INPUT_OPEN]['#default_value']);
    self::assertCount(0, $source->validateComponentInput([ViewTemplateItemLink::INPUT_OPEN => ViewTemplateItemLink::OPEN_NEW], '', NULL));
    self::assertCount(1, $source->validateComponentInput([ViewTemplateItemLink::INPUT_OPEN => 'nonsense'], '', NULL));
  }

  /**
   * Renders a template, with a item link around "View item" content.
   *
   * @param \Drupal\canvas_view_template\Entity\ViewTemplate $template
   *   The template.
   * @param array<int, string> $rows
   *   Each row's render, as plain markup.
   * @param array<int, string|null> $urls
   *   The page of each row.
   */
  private function renderTemplate(ViewTemplate $template, array $rows, array $urls): string {
    $result = $this->renderWithData($template, new ViewRenderData(rowRenders: $rows, itemUrls: $urls));
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * Builds a "View item link" with a "View item" in its slot.
   *
   * @return array{0: array, 1: array}
   *   The link's tree item and its child's.
   */
  private function linkWithItem(string $open, ?string $parent_uuid = NULL, ?string $parent_slot = NULL): array {
    $link_component = Component::load(ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID);
    self::assertNotNull($link_component);
    $item_component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID);
    self::assertNotNull($item_component);
    $uuid = \Drupal::service('uuid')->generate();
    $link = [
      'uuid' => $uuid,
      'component_id' => $link_component->id(),
      'component_version' => $link_component->getActiveVersion(),
      'inputs' => [ViewTemplateItemLink::INPUT_OPEN => $open],
    ];
    if ($parent_uuid !== NULL) {
      $link['parent_uuid'] = $parent_uuid;
      $link['slot'] = $parent_slot;
    }
    $item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $item_component->id(),
      'component_version' => $item_component->getActiveVersion(),
      'inputs' => [],
      'parent_uuid' => $uuid,
      'slot' => ViewTemplateItemLink::SLOT_NAME,
    ];
    return [$link, $item];
  }

}
