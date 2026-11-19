<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Render\ViewFieldRenders;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Markup;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests "View field": one field of the current item.
 */
#[CoversClass(ViewTemplateField::class)]
#[CoversClass(ViewFieldRenders::class)]
#[Group('canvas_view_template')]
final class ViewTemplateFieldTest extends ViewTemplateKernelTestBase {

  /**
   * The "View field" ComponentSource plugin instance.
   */
  private ViewTemplateField $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateField::class, $source);
    $this->source = $source;
  }

  /**
   * Two rows' fields, as ViewFieldRenders collects them.
   */
  private function fieldData(): array {
    return [
      'rows' => [
        ['title' => ['#markup' => 'FIRST_TITLE'], 'summary' => ['#markup' => 'FIRST_SUMMARY'], 'empty_one' => []],
        ['title' => ['#markup' => 'SECOND_TITLE'], 'summary' => ['#markup' => 'SECOND_SUMMARY'], 'empty_one' => []],
        ['title' => ['#markup' => 'THIRD_TITLE'], 'summary' => ['#markup' => 'THIRD_SUMMARY'], 'empty_one' => []],
      ],
      'labels' => ['title' => 'Title', 'summary' => 'Summary', 'empty_one' => 'Nothing'],
    ];
  }

  /**
   * The settings of a "View field".
   */
  private function settings(string $field, string $element = 'div', string $label = 'hidden'): array {
    return [
      ViewTemplateField::INPUT_FIELD => $field,
      ViewTemplateField::INPUT_ELEMENT => $element,
      ViewTemplateField::INPUT_LABEL => $label,
    ];
  }

  /**
   * A "View field" tree item.
   */
  private function fieldItem(array $inputs, ?string $parent_uuid = NULL): array {
    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    self::assertNotNull($component);
    $item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component->id(),
      'component_version' => $component->getActiveVersion(),
      // Stored flat: Canvas wraps these under `settings` when it hydrates.
      'inputs' => $inputs + $this->source->getDefaultExplicitInput(),
    ];
    if ($parent_uuid !== NULL) {
      $item += ['parent_uuid' => $parent_uuid, 'slot' => ViewTemplateRepeater::SLOT_NAME];
    }
    return $item;
  }

  /**
   * Renders a template of "View field" items, inside a repeater or not.
   *
   * @param array $field_inputs
   *   The settings of each "View field".
   * @param array $repeater_inputs
   *   The repeater's settings, or NULL to place the fields on their own.
   */
  private function renderFields(array $field_inputs, ?array $repeater_inputs = []): string {
    $tree = [];
    $parent = NULL;
    if ($repeater_inputs !== NULL) {
      $repeater = Component::load(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
      self::assertNotNull($repeater);
      $parent = \Drupal::service('uuid')->generate();
      $tree[] = [
        'uuid' => $parent,
        'component_id' => $repeater->id(),
        'component_version' => $repeater->getActiveVersion(),
        'inputs' => $repeater_inputs + [ViewTemplateRepeater::INPUT_OFFSET => 0],
      ];
    }
    foreach ($field_inputs as $inputs) {
      $tree[] = $this->fieldItem($inputs, $parent);
    }
    $view_template = ViewTemplate::create(['id' => 'field_test', 'label' => 'Field test', 'component_tree' => $tree]);
    $rows = [['#markup' => 'ROW_ONE'], ['#markup' => 'ROW_TWO'], ['#markup' => 'ROW_THREE']];
    $result = $this->renderWithData($view_template, new ViewRenderData(rows: ['#markup' => 'ROWS'], rowRenders: $rows, fieldData: $this->fieldData()));
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * Each repeated item shows its own row's value.
   */
  public function testEachRepeatedItemShowsItsOwnValue(): void {
    $html = $this->renderFields([[ViewTemplateField::INPUT_FIELD => 'title', ViewTemplateField::INPUT_ELEMENT => 'h3']]);
    self::assertSame(3, \substr_count($html, '<h3>'));
    self::assertLessThan(\strpos($html, 'SECOND_TITLE'), \strpos($html, 'FIRST_TITLE'));
    self::assertLessThan(\strpos($html, 'THIRD_TITLE'), \strpos($html, 'SECOND_TITLE'));
    self::assertStringNotContainsString('SECOND_SUMMARY', $html);
  }

  /**
   * The repeater's limit and offset pick which rows' values are shown.
   */
  public function testRepeaterOffsetAndLimitPickTheRowsFields(): void {
    $html = $this->renderFields(
      [[ViewTemplateField::INPUT_FIELD => 'title']],
      [ViewTemplateRepeater::INPUT_OFFSET => 1, ViewTemplateRepeater::INPUT_LIMIT => 1],
    );
    self::assertStringContainsString('SECOND_TITLE', $html);
    self::assertStringNotContainsString('FIRST_TITLE', $html);
    self::assertStringNotContainsString('THIRD_TITLE', $html);
  }

  /**
   * Outside a repeater, a field shows the first item's value.
   */
  public function testOutsideRepeaterShowsFirstValue(): void {
    $html = $this->renderFields([[ViewTemplateField::INPUT_FIELD => 'summary']], NULL);
    self::assertStringContainsString('FIRST_SUMMARY', $html);
    self::assertStringNotContainsString('SECOND_SUMMARY', $html);
  }

  /**
   * The element wraps the value, or not at all.
   */
  public function testElement(): void {
    $html = $this->renderFields([$this->settings('title', 'p')], NULL);
    self::assertMatchesRegularExpression('#<p>\s*FIRST_TITLE\s*</p>#', $html);
    $none = $this->renderFields([$this->settings('title', ViewTemplateField::ELEMENT_NONE)], NULL);
    self::assertStringContainsString('FIRST_TITLE', $none);
    self::assertStringNotContainsString('<p>', $none);
    self::assertStringNotContainsString('<div>', $none);
  }

  /**
   * The label is hidden, above or inline.
   */
  public function testLabel(): void {
    $hidden = $this->renderFields([[ViewTemplateField::INPUT_FIELD => 'title']], NULL);
    self::assertStringNotContainsString('cvt-field__label', $hidden);

    $above = $this->renderFields([$this->settings('title', 'div', ViewTemplateField::LABEL_ABOVE)], NULL);
    self::assertMatchesRegularExpression('#<div class="cvt-field__label">Title</div>#', $above);

    $inline = $this->renderFields([$this->settings('title', 'div', ViewTemplateField::LABEL_INLINE)], NULL);
    self::assertMatchesRegularExpression('#<span class="cvt-field__label">Title</span>#', $inline);
    self::assertMatchesRegularExpression('#<div class="cvt-field cvt-field--inline">\s*<span class="cvt-field__label">Title</span>\s*<div class="cvt-field__value">\s*FIRST_TITLE#', $inline);
    // The layout wrapper stays a div; the element wraps the value.
    $heading = $this->renderFields([$this->settings('title', 'h3', ViewTemplateField::LABEL_INLINE)], NULL);
    self::assertMatchesRegularExpression('#<div class="cvt-field cvt-field--inline">.*<h3 class="cvt-field__value">\s*FIRST_TITLE\s*</h3>#s', $heading);
  }

  /**
   * A block inside a `p`, `span` or heading would be closed early, so a div.
   */
  public function testBlockMarkupIsNotWrappedInPhrasingElements(): void {
    $body = '<div class="field"><p>BODY_TEXT</p></div>';
    $data = [
      'rows' => [['body' => ['#markup' => Markup::create($body)], 'title' => ['#markup' => 'PLAIN_TITLE']]],
      'labels' => ['body' => 'Body', 'title' => 'Title'],
    ];
    $render = function (string $field, string $element, string $label) use ($data): string {
      $view_template = ViewTemplate::create([
        'id' => 'field_test',
        'label' => 'Field test',
        'component_tree' => [$this->fieldItem($this->settings($field, $element, $label))],
      ]);
      $result = $this->renderWithData($view_template, new ViewRenderData(rows: ['#markup' => 'ROWS'], rowRenders: [['#markup' => 'ROW']], fieldData: $data));
      return (string) $this->renderer->renderInIsolation($result);
    };
    foreach (['p', 'span', 'h2'] as $element) {
      foreach ([ViewTemplateField::LABEL_HIDDEN, ViewTemplateField::LABEL_INLINE] as $label) {
        $html = $render('body', $element, $label);
        // The wrapper directly around the block is a div, not the element.
        self::assertDoesNotMatchRegularExpression("#<$element\\b[^>]*>\\s*<div class=\"field\">#", $html, "$element, $label");
        self::assertMatchesRegularExpression('#<div\\b[^>]*>\\s*<div class="field"><p>BODY_TEXT</p></div>#', $html, "$element, $label");
      }
    }
    // Plain text keeps the chosen element.
    $html = $render('title', 'p', ViewTemplateField::LABEL_INLINE);
    self::assertMatchesRegularExpression('#<p class="cvt-field__value">\s*PLAIN_TITLE\s*</p>#', $html);
  }

  /**
   * The label is plain text: markup in it is escaped.
   */
  public function testLabelIsEscaped(): void {
    $data = ['rows' => [['title' => ['#markup' => 'T']]], 'labels' => ['title' => '<b>Bold</b> & co']];
    $view_template = ViewTemplate::create([
      'id' => 'field_test',
      'label' => 'Field test',
      'component_tree' => [$this->fieldItem($this->settings('title', 'div', ViewTemplateField::LABEL_ABOVE))],
    ]);
    $result = $this->renderWithData($view_template, new ViewRenderData(rows: ['#markup' => 'ROWS'], rowRenders: [['#markup' => 'ROW']], fieldData: $data));
    $html = (string) $this->renderer->renderInIsolation($result);
    self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; &amp; co', $html);
    self::assertStringNotContainsString('<b>', $html);
  }

  /**
   * An empty, missing or unset field outputs nothing, not an empty element.
   */
  public function testNothingWithoutValue(): void {
    foreach (['empty_one', 'no_such_field', ''] as $field) {
      $html = $this->renderFields([$this->settings($field, 'h2', ViewTemplateField::LABEL_ABOVE)]);
      self::assertStringNotContainsString('<h2', $html, "Field '$field'");
      self::assertStringNotContainsString('cvt-field__label', $html, "Field '$field'");
    }
  }

  /**
   * Stored settings are validated.
   */
  public function testValidateComponentInput(): void {
    self::assertCount(0, $this->source->validateComponentInput($this->source->getDefaultExplicitInput(), 'u', NULL));
    self::assertCount(0, $this->source->validateComponentInput([ViewTemplateField::INPUT_FIELD => 'field_image_1'], 'u', NULL));
    self::assertCount(1, $this->source->validateComponentInput([ViewTemplateField::INPUT_FIELD => 'Not A Field!'], 'u', NULL));
    self::assertCount(1, $this->source->validateComponentInput([ViewTemplateField::INPUT_ELEMENT => 'script'], 'u', NULL));
    self::assertCount(1, $this->source->validateComponentInput([ViewTemplateField::INPUT_LABEL => 'sideways'], 'u', NULL));
  }

  /**
   * Defaults, and a new instance getting them.
   */
  public function testDefaultsAndClientModel(): void {
    $defaults = [
      ViewTemplateField::INPUT_FIELD => '',
      ViewTemplateField::INPUT_ELEMENT => 'div',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    self::assertSame($defaults, $this->source->getDefaultExplicitInput());
    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    self::assertSame($defaults, $this->source->clientModelToInput('u', $component, ['resolved' => []], NULL));
    $set = [
      ViewTemplateField::INPUT_FIELD => 'title',
      ViewTemplateField::INPUT_ELEMENT => 'h2',
      ViewTemplateField::INPUT_LABEL => 'above',
    ];
    self::assertSame($set, $this->source->clientModelToInput('u', $component, ['resolved' => $set], NULL));
  }

  /**
   * With no fields to offer, the field is typed; the others are lists.
   */
  public function testInstanceFormFallsBackToTextfield(): void {
    foreach (View::loadMultiple() as $view) {
      $view->delete();
    }
    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    $form_state = new FormState();
    $form = $this->source->buildComponentInstanceForm([], $form_state, $component, 'u', [ViewTemplateField::INPUT_FIELD => 'title']);
    self::assertSame('textfield', $form[ViewTemplateField::INPUT_FIELD]['#type']);
    self::assertSame('title', $form[ViewTemplateField::INPUT_FIELD]['#default_value']);
    self::assertSame('select', $form[ViewTemplateField::INPUT_ELEMENT]['#type']);
    self::assertSame('select', $form[ViewTemplateField::INPUT_LABEL]['#type']);
    // Custom names would not survive the client's form serialization.
    foreach ([ViewTemplateField::INPUT_FIELD, ViewTemplateField::INPUT_ELEMENT, ViewTemplateField::INPUT_LABEL] as $key) {
      self::assertArrayNotHasKey('#name', $form[$key]);
    }
  }

}
