<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\views\Views;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View field": one field of the current item.
 *
 * Needs the "Fields" row style. The renderer resumes the Fiber with
 * `['value' => <render array>, 'label' => <string>]`; empty value, no output.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View field'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateField extends ViewTemplateComponentSourceBase implements TrustedCallbackInterface {

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_field';

  public const string LOCAL_ID = 'view_field';

  /**
   * The explicit input key for the field's machine name.
   */
  public const string INPUT_FIELD = 'field';

  /**
   * The explicit input key for the wrapping element.
   */
  public const string INPUT_ELEMENT = 'element';

  /**
   * The explicit input key for how the label is shown.
   */
  public const string INPUT_LABEL = 'label';

  /**
   * The "Element" value for the value alone, with no wrapping element.
   */
  public const string ELEMENT_NONE = 'none';

  /**
   * Block-level tags, which a `p`, `span` or heading cannot hold.
   */
  private const string BLOCK_MARKUP = '#<(?:address|article|aside|blockquote|details|div|dl|fieldset|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul)[\s>/]#i';

  public const string DEFAULT_ELEMENT = 'div';

  public const string LABEL_HIDDEN = 'hidden';

  public const string LABEL_ABOVE = 'above';

  public const string LABEL_INLINE = 'inline';

  /**
   * The field requested by the most recently suspended instance.
   */
  protected ?string $currentFieldId = NULL;

  /**
   * The machine name of the field the suspended instance shows.
   */
  public function getCurrentFieldId(): ?string {
    return $this->currentFieldId;
  }

  /**
   * The allowed "Element" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by element.
   */
  public static function elementOptions(): array {
    return [
      'div' => new TranslatableMarkup('Block (div)'),
      'p' => new TranslatableMarkup('Paragraph'),
      'span' => new TranslatableMarkup('Inline (span)'),
      'h1' => new TranslatableMarkup('Heading 1'),
      'h2' => new TranslatableMarkup('Heading 2'),
      'h3' => new TranslatableMarkup('Heading 3'),
      'h4' => new TranslatableMarkup('Heading 4'),
      'h5' => new TranslatableMarkup('Heading 5'),
      'h6' => new TranslatableMarkup('Heading 6'),
      self::ELEMENT_NONE => new TranslatableMarkup('None (the field as it is)'),
    ];
  }

  /**
   * The allowed "Label" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by value.
   */
  public static function labelOptions(): array {
    return [
      self::LABEL_HIDDEN => new TranslatableMarkup('Hidden'),
      self::LABEL_ABOVE => new TranslatableMarkup('Above'),
      self::LABEL_INLINE => new TranslatableMarkup('Inline'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View field');
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $settings = $this->settingsFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);

    if (\Fiber::getCurrent() !== NULL) {
      $this->currentFieldId = $settings[self::INPUT_FIELD];
      $field = \Fiber::suspend($this);
      if (\is_array($field)) {
        return $this->buildField($field, $settings);
      }
    }
    if ($isPreview) {
      return $this->buildMarkerStylePreview(
        'field',
        $this->t('View field'),
        $settings[self::INPUT_FIELD] !== ''
          ? $this->t('The "@field" field appears here.', ['@field' => $settings[self::INPUT_FIELD]])
          : $this->t('Choose a field in the settings.'),
        [self::elementOptions()[$settings[self::INPUT_ELEMENT]]],
      );
    }
    return [];
  }

  /**
   * Builds the output from a field's value and label.
   *
   * @param array $field
   *   The `value` and `label`, or empty.
   * @param array<string, string> $settings
   *   This instance's settings.
   *
   * @return array
   *   The render array, empty if there is no value.
   */
  private function buildField(array $field, array $settings): array {
    $value = $field['value'] ?? [];
    if ($value === []) {
      return [];
    }
    $label = (string) ($field['label'] ?? '');
    $element = $settings[self::INPUT_ELEMENT];
    $label_build = [
      '#type' => 'html_tag',
      '#tag' => $settings[self::INPUT_LABEL] === self::LABEL_ABOVE ? 'div' : 'span',
      '#attributes' => ['class' => ['cvt-field__label']],
      'text' => ['#plain_text' => $label],
    ];
    if ($settings[self::INPUT_LABEL] === self::LABEL_INLINE && $label !== '') {
      // A div, whatever the element: it only lays out the label and the value.
      return [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['cvt-field', 'cvt-field--inline']],
        'label' => $label_build,
        'value' => $this->wrapValue($value, $element === self::ELEMENT_NONE ? 'div' : $element, ['cvt-field__value']),
        '#attached' => ['library' => ['canvas_view_template/field']],
      ];
    }
    $body = $element === self::ELEMENT_NONE ? $value : $this->wrapValue($value, $element);
    if ($settings[self::INPUT_LABEL] === self::LABEL_HIDDEN || $label === '') {
      return $body;
    }
    return ['label' => $label_build, 'body' => $body];
  }

  /**
   * Wraps a value in an element, as a `div` if it is a block in a `p`, etc.
   *
   * The parser would close a `p`, `span` or heading at the first block inside
   * it, so the markup is only known once the value is rendered.
   *
   * @param array $value
   *   The value's render array.
   * @param string $tag
   *   The chosen element.
   * @param string[] $classes
   *   Classes for the element.
   *
   * @return array
   *   A render array.
   */
  private function wrapValue(array $value, string $tag, array $classes = []): array {
    return [
      '#pre_render' => [[self::class, 'preRenderWrap']],
      '#tag' => $tag,
      '#attributes' => $classes === [] ? [] : ['class' => $classes],
      'value' => $value,
    ];
  }

  /**
   * Render callback: renders the value inside its element.
   */
  public static function preRenderWrap(array $element): array {
    $markup = (string) \Drupal::service('renderer')->render($element['value']);
    $tag = $element['#tag'];
    if ($tag !== 'div' && \preg_match(self::BLOCK_MARKUP, $markup) === 1) {
      $tag = 'div';
    }
    $attributes = new Attribute($element['#attributes']);
    $element['#markup'] = Markup::create('<' . $tag . $attributes . '>' . $markup . '</' . $tag . '>');
    unset($element['value']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderWrap'];
  }

  /**
   * Returns the settings, falling back to defaults for invalid values.
   *
   * @return array<string, string>
   *   The field, element and label.
   */
  private function settingsFrom(array $settings): array {
    $field = $settings[self::INPUT_FIELD] ?? '';
    $element = $settings[self::INPUT_ELEMENT] ?? self::DEFAULT_ELEMENT;
    $label = $settings[self::INPUT_LABEL] ?? self::LABEL_HIDDEN;
    return [
      self::INPUT_FIELD => \is_string($field) && \preg_match('/^[a-z0-9_]*$/', $field) ? $field : '',
      self::INPUT_ELEMENT => \is_string($element) && \array_key_exists($element, self::elementOptions()) ? $element : self::DEFAULT_ELEMENT,
      self::INPUT_LABEL => \is_string($label) && \array_key_exists($label, self::labelOptions()) ? $label : self::LABEL_HIDDEN,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [
      self::INPUT_FIELD => '',
      self::INPUT_ELEMENT => self::DEFAULT_ELEMENT,
      self::INPUT_LABEL => self::LABEL_HIDDEN,
    ];
  }

  /**
   * The fields to choose from.
   *
   * Order: previewed display, displays using this template, all Views.
   *
   * @return array{options: array<string, string>, source: string}
   *   Admin labels by field name, and `previewed`, `using` or `all`.
   */
  private function fieldChoices(?EntityInterface $entity): array {
    $displays = [];
    $source = 'all';
    if ($entity instanceof ViewTemplate) {
      $template_id = (string) $entity->id();
      $choice = \Drupal::service(ViewTemplatePreviewSelection::class)->get($template_id);
      if ($choice !== NULL) {
        $displays = [$choice];
        $source = 'previewed';
      }
      else {
        foreach (\Drupal::service(ViewTemplateUsage::class)->displaysUsing($template_id) as $display) {
          $displays[] = ['view' => $display['view'], 'display' => $display['display']];
        }
        $source = $displays !== [] ? 'using' : 'all';
      }
    }
    $options = [];
    if ($source === 'all') {
      // Raw config: building every View's handlers would be slow on big sites.
      $config_factory = \Drupal::configFactory();
      $usage = \Drupal::service(ViewTemplateUsage::class);
      $account = \Drupal::currentUser();
      foreach ($config_factory->loadMultiple($config_factory->listAll('views.view.')) as $config) {
        if (!$config->get('status') || !$usage->canSee((string) $config->get('id'), NULL, $account)) {
          continue;
        }
        foreach ($config->get('display.default.display_options.fields') ?? [] as $id => $field) {
          $options[(string) $id] ??= (string) (($field['admin_label'] ?? '') ?: ($field['label'] ?? '') ?: $id);
        }
      }
    }
    foreach ($displays as $choice) {
      $view = Views::getView($choice['view']);
      if ($view === NULL || !$view->setDisplay($choice['display']) || !$view->access($choice['display'])) {
        continue;
      }
      foreach ($view->display_handler->getHandlers('field') as $id => $handler) {
        $options[(string) $id] ??= (string) $handler->adminLabel();
      }
    }
    \asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return ['options' => $options, 'source' => $source];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $values = $this->settingsFrom($inputValues);
    // No custom `#name`: the client's form serialization ignores it, so the
    // value would silently never be saved.
    ['options' => $choices, 'source' => $source] = $this->fieldChoices($entity);
    if ($choices !== []) {
      if ($values[self::INPUT_FIELD] !== '' && !isset($choices[$values[self::INPUT_FIELD]])) {
        $choices[$values[self::INPUT_FIELD]] = $values[self::INPUT_FIELD];
      }
      $form[self::INPUT_FIELD] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#description' => match ($source) {
          'previewed' => $this->t('The fields of the view chosen under Page data, Preview with view.'),
          'using' => $this->t('The fields of the views that use this template. Choose a view under Page data, Preview with view, to see only its fields.'),
          default => $this->t('The fields of the views on this site. Choose a view under Page data, Preview with view, to see only its fields. Needs a view that uses the Fields row style.'),
        },
        '#options' => ['' => $this->t('- Choose a field -')] + $choices,
        '#default_value' => $values[self::INPUT_FIELD],
      ];
    }
    else {
      $form[self::INPUT_FIELD] = [
        '#type' => 'textfield',
        '#title' => $this->t('Field'),
        '#description' => $this->t("The field's machine name. Choose a view under Page data, Preview with view, to pick from its fields instead. Needs a view that uses the Fields row style."),
        '#default_value' => $values[self::INPUT_FIELD],
        '#maxlength' => 128,
      ];
    }
    $form[self::INPUT_ELEMENT] = [
      '#type' => 'select',
      '#title' => $this->t('Element'),
      '#options' => self::elementOptions(),
      '#default_value' => $values[self::INPUT_ELEMENT],
    ];
    $form[self::INPUT_LABEL] = [
      '#type' => 'select',
      '#title' => $this->t('Label'),
      '#description' => $this->t("Shows the field's label, as set in the view. Inline puts it beside the value, with any element."),
      '#options' => self::labelOptions(),
      '#default_value' => $values[self::INPUT_LABEL],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $defaults = $this->getDefaultExplicitInput();
    $field = $inputValues[self::INPUT_FIELD] ?? $defaults[self::INPUT_FIELD];
    if (!\is_string($field) || !\preg_match('/^[a-z0-9_]*$/', $field)) {
      $violations->add($this->violation($this->t('A field machine name has only lowercase letters, numbers and underscores.'), $inputValues, self::INPUT_FIELD, $field));
    }
    $element = $inputValues[self::INPUT_ELEMENT] ?? $defaults[self::INPUT_ELEMENT];
    if (!\is_string($element) || !\array_key_exists($element, self::elementOptions())) {
      $violations->add($this->violation($this->t('Choose an element.'), $inputValues, self::INPUT_ELEMENT, $element));
    }
    $label = $inputValues[self::INPUT_LABEL] ?? $defaults[self::INPUT_LABEL];
    if (!\is_string($label) || !\array_key_exists($label, self::labelOptions())) {
      $violations->add($this->violation($this->t('Choose how to show the label.'), $inputValues, self::INPUT_LABEL, $label));
    }
    return $violations;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [
      'type' => 'mapping',
      'mapping' => [
        self::INPUT_FIELD => ['type' => 'string'],
        self::INPUT_ELEMENT => ['type' => 'string'],
        self::INPUT_LABEL => ['type' => 'string'],
      ],
    ];
  }

}
