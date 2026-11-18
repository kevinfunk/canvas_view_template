<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View title": the View's title, as a heading of your choosing.
 *
 * Includes contextual-filter overrides. The default is h2 as the page's own
 * title is usually the h1. The renderer resumes the Fiber with the title render
 * array, or `[]` when the View has no title.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View title'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateTitle extends ComponentSourceBase {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_title';

  public const string LOCAL_ID = 'view_title';

  /**
   * The explicit input key for the heading element.
   */
  public const string INPUT_ELEMENT = 'element';

  /**
   * The "Element" value for plain text with no wrapping element.
   */
  public const string ELEMENT_NONE = 'none';

  /**
   * The default element.
   */
  public const string DEFAULT_ELEMENT = 'h2';

  /**
   * The key `hydrateComponent()` wraps explicit input under.
   */
  private const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The allowed "Element" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by element (`h1`-`h6`, or `none`).
   */
  public static function elementOptions(): array {
    return [
      'h1' => new TranslatableMarkup('Heading 1'),
      'h2' => new TranslatableMarkup('Heading 2'),
      'h3' => new TranslatableMarkup('Heading 3'),
      'h4' => new TranslatableMarkup('Heading 4'),
      'h5' => new TranslatableMarkup('Heading 5'),
      'h6' => new TranslatableMarkup('Heading 6'),
      self::ELEMENT_NONE => new TranslatableMarkup('Plain text (no element)'),
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
    return new TranslatableMarkup('View title');
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $element = $this->elementFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);

    if (\Fiber::getCurrent() !== NULL) {
      $title = \Fiber::suspend($this);
      if (\is_array($title)) {
        if ($title === []) {
          return [];
        }
        return $element === self::ELEMENT_NONE ? $title : [
          '#type' => 'html_tag',
          '#tag' => $element,
          'title' => $title,
        ];
      }
    }
    if ($isPreview) {
      return $this->buildMarkerStylePreview(
        'title',
        $this->t('View title'),
        $this->t("The view's title appears here."),
        [self::elementOptions()[$element]],
      );
    }
    return [];
  }

  /**
   * Returns a valid element from stored settings, or the default.
   */
  private function elementFrom(array $settings): string {
    $element = $settings[self::INPUT_ELEMENT] ?? self::DEFAULT_ELEMENT;
    return \is_string($element) && \array_key_exists($element, self::elementOptions()) ? $element : self::DEFAULT_ELEMENT;
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
    return [self::INPUT_ELEMENT => self::DEFAULT_ELEMENT];
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
    // No custom `#name`: the client's form serialization ignores it, so the
    // value would silently never be saved.
    $form[self::INPUT_ELEMENT] = [
      '#type' => 'select',
      '#title' => $this->t('Element'),
      '#description' => $this->t('The heading level, or plain text. On a page display the page title is usually the h1, so a lower level is a safe default.'),
      '#options' => self::elementOptions(),
      '#default_value' => $this->elementFrom($inputValues),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $is_new_instance = $client_model === ['resolved' => []];
    $resolved = $is_new_instance ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    return [self::INPUT_ELEMENT => $resolved[self::INPUT_ELEMENT] ?? self::DEFAULT_ELEMENT];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $element = $inputValues[self::INPUT_ELEMENT] ?? self::DEFAULT_ELEMENT;
    if (!\is_string($element) || !\array_key_exists($element, self::elementOptions())) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Choose a heading level or plain text.'),
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
        self::INPUT_ELEMENT => ['type' => 'string'],
      ],
    ];
  }

}
