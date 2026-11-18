<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_view_template\Render\ViewResultSummary;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View result summary": for example "Displaying 1 - 10 of 54".
 *
 * "Format" uses the tokens of the Views "Result summary" area. The renderer
 * resumes the Fiber with the token replacements, or `[]` when there are no
 * results.
 *
 * @see \Drupal\canvas_view_template\Render\ViewResultSummary
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View result summary'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateSummary extends ComponentSourceBase {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_summary';

  public const string LOCAL_ID = 'view_summary';

  /**
   * The explicit input key for the text, with tokens.
   */
  public const string INPUT_FORMAT = 'format';

  /**
   * The explicit input key for the wrapping element.
   */
  public const string INPUT_ELEMENT = 'element';

  /**
   * The "Element" value for the text with no wrapping element.
   */
  public const string ELEMENT_NONE = 'none';

  public const string DEFAULT_FORMAT = 'Displaying @start - @end of @total';

  public const string DEFAULT_ELEMENT = 'p';

  private const int MAX_FORMAT_LENGTH = 255;

  /**
   * The key `hydrateComponent()` wraps explicit input under.
   */
  private const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The allowed "Element" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by element.
   */
  public static function elementOptions(): array {
    return [
      'p' => new TranslatableMarkup('Paragraph'),
      'div' => new TranslatableMarkup('Block (div)'),
      'span' => new TranslatableMarkup('Inline (span)'),
      self::ELEMENT_NONE => new TranslatableMarkup('None (plain text)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function determineDefaultFolder(): string {
    return ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View result summary');
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $settings = $this->settingsFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);

    if (\Fiber::getCurrent() !== NULL) {
      $tokens = \Fiber::suspend($this);
      if (\is_array($tokens)) {
        if ($tokens === []) {
          return [];
        }
        $text = ['#plain_text' => \strtr($settings[self::INPUT_FORMAT], $tokens)];
        return $settings[self::INPUT_ELEMENT] === self::ELEMENT_NONE ? $text : [
          '#type' => 'html_tag',
          '#tag' => $settings[self::INPUT_ELEMENT],
          'text' => $text,
        ];
      }
    }
    if ($isPreview) {
      return $this->buildMarkerStylePreview(
        'summary',
        $this->t('View result summary'),
        $this->t('For example "Displaying 1 - 10 of 54".'),
        [self::elementOptions()[$settings[self::INPUT_ELEMENT]]],
      );
    }
    return [];
  }

  /**
   * Returns the settings, falling back to defaults for invalid values.
   *
   * @return array<string, string>
   *   The format and element.
   */
  private function settingsFrom(array $settings): array {
    $format = $settings[self::INPUT_FORMAT] ?? self::DEFAULT_FORMAT;
    $element = $settings[self::INPUT_ELEMENT] ?? self::DEFAULT_ELEMENT;
    return [
      self::INPUT_FORMAT => \is_string($format) && \mb_strlen($format) <= self::MAX_FORMAT_LENGTH ? $format : self::DEFAULT_FORMAT,
      self::INPUT_ELEMENT => \is_string($element) && \array_key_exists($element, self::elementOptions()) ? $element : self::DEFAULT_ELEMENT,
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
      self::INPUT_FORMAT => self::DEFAULT_FORMAT,
      self::INPUT_ELEMENT => self::DEFAULT_ELEMENT,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    try {
      return $item->getInputs() ?? $this->getDefaultExplicitInput();
    }
    catch (MissingComponentInputsException) {
      return $this->getDefaultExplicitInput();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return [self::EXPLICIT_INPUT_NAME => $explicit_input + $this->getDefaultExplicitInput()];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => $explicit_input + $this->getDefaultExplicitInput()];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    return [
      'build' => $this->renderComponent([], $component->getSlotDefinitions(), $component->uuid(), TRUE),
      'metadata' => [
        'slots' => [],
      ],
    ];
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
    $tokens = [];
    foreach (ViewResultSummary::tokens() as $token => $meaning) {
      $tokens[] = $token;
    }
    // No custom `#name`: the client's form serialization ignores it, so the
    // value would silently never be saved.
    $form[self::INPUT_FORMAT] = [
      '#type' => 'textfield',
      '#title' => $this->t('Format'),
      '#description' => $this->t('The text. You can use: @tokens', ['@tokens' => \implode(', ', $tokens)]),
      '#default_value' => $values[self::INPUT_FORMAT],
      '#maxlength' => self::MAX_FORMAT_LENGTH,
    ];
    $form[self::INPUT_ELEMENT] = [
      '#type' => 'select',
      '#title' => $this->t('Element'),
      '#options' => self::elementOptions(),
      '#default_value' => $values[self::INPUT_ELEMENT],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $is_new_instance = $client_model === ['resolved' => []];
    $resolved = $is_new_instance ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    $defaults = $this->getDefaultExplicitInput();
    return [
      self::INPUT_FORMAT => $resolved[self::INPUT_FORMAT] ?? $defaults[self::INPUT_FORMAT],
      self::INPUT_ELEMENT => $resolved[self::INPUT_ELEMENT] ?? $defaults[self::INPUT_ELEMENT],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $defaults = $this->getDefaultExplicitInput();
    $format = $inputValues[self::INPUT_FORMAT] ?? $defaults[self::INPUT_FORMAT];
    if (!\is_string($format) || \mb_strlen($format) > self::MAX_FORMAT_LENGTH) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('The format can be at most @max characters.', ['@max' => self::MAX_FORMAT_LENGTH]),
        NULL,
        [],
        $inputValues,
        self::INPUT_FORMAT,
        $format,
      ));
    }
    $element = $inputValues[self::INPUT_ELEMENT] ?? $defaults[self::INPUT_ELEMENT];
    if (!\is_string($element) || !\array_key_exists($element, self::elementOptions())) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Choose an element.'),
        NULL,
        [],
        $inputValues,
        self::INPUT_ELEMENT,
        $element,
      ));
    }
    return $violations;
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return [
      'module' => ['canvas_view_template'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [
      'type' => 'mapping',
      'mapping' => [
        self::INPUT_FORMAT => ['type' => 'string'],
        self::INPUT_ELEMENT => ['type' => 'string'],
      ],
    ];
  }

}
