<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View condition": shows its content only when a condition is met.
 *
 * Otherwise nothing renders, not even a wrapper. The renderer resumes the
 * Fiber with `[]` (not met) or NULL (met: render the slot via ::setSlots()).
 *
 * @see \Drupal\canvas_view_template\Render\ViewConditions
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View condition'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateCondition extends ComponentSourceBase implements ComponentSourceWithSlotsInterface {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_condition';

  public const string LOCAL_ID = 'view_condition';

  /**
   * The name of the slot the conditional content goes in.
   */
  public const string SLOT_NAME = 'content';

  /**
   * The explicit input key for the condition.
   */
  public const string INPUT_CONDITION = 'condition';

  /**
   * The key `hydrateComponent()` wraps explicit input under.
   */
  private const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The tree UUID of the instance most recently suspended for rendering.
   */
  protected ?string $currentComponentUuid = NULL;

  /**
   * The tree UUID of the suspended instance.
   */
  public function getCurrentComponentUuid(): ?string {
    return $this->currentComponentUuid;
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
    return new TranslatableMarkup('View condition');
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    // Not translated: the version hash includes these, so it must not depend
    // on the interface language.
    return [
      self::SLOT_NAME => [
        'title' => 'Content',
        'description' => 'Shown only when the condition is met.',
        // A non-empty example makes Canvas treat this as a slot-bearing
        // component and draw its empty-slot drop target in the editor.
        'examples' => [''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // Unflagged means the renderer resumed us with `[]` (condition not met).
    if (empty($build['#cvt_show_slot'])) {
      return;
    }
    $slot_build = $slots[self::SLOT_NAME] ?? [];
    if (empty($build['#canvas_is_preview'])) {
      $build[self::SLOT_NAME] = $slot_build;
      return;
    }
    // Editor: the slot-marker HTML comments must sit inside a real wrapper.
    $uuid = $build['#canvas_component_uuid'] ?? NULL;
    if (\is_string($uuid)) {
      $slot_build['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $uuid, self::SLOT_NAME));
      $slot_build['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $uuid, self::SLOT_NAME));
    }
    $build[self::SLOT_NAME] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cvt-marker__slot']],
      'content' => $slot_build,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    if (\Fiber::getCurrent() !== NULL) {
      $this->currentComponentUuid = $componentUuid;
      $injected = \Fiber::suspend($this);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    if ($isPreview) {
      $condition = $this->conditionFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);
      $build = $this->buildMarkerStylePreview(
        'condition',
        $this->t('View condition'),
        $this->t('Shows what is inside only when its condition is met.'),
        [ViewConditions::options()[$condition]],
      );
      $build[self::SLOT_NAME] = [];
      $build['#canvas_component_uuid'] = $componentUuid;
      $build['#canvas_is_preview'] = TRUE;
      $build['#cvt_show_slot'] = TRUE;
      return $build;
    }
    return ['#cvt_show_slot' => TRUE, self::SLOT_NAME => []];
  }

  /**
   * Returns a valid condition from stored settings, or the default.
   */
  private function conditionFrom(array $settings): string {
    $condition = $settings[self::INPUT_CONDITION] ?? ViewConditions::DEFAULT;
    return \is_string($condition) && \array_key_exists($condition, ViewConditions::options()) ? $condition : ViewConditions::DEFAULT;
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
    return [self::INPUT_CONDITION => ViewConditions::DEFAULT];
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
    $hydrated = [self::EXPLICIT_INPUT_NAME => $explicit_input + $this->getDefaultExplicitInput()];
    // The `slots` key is what makes Canvas call ::setSlots().
    if (!empty($slot_definitions)) {
      $hydrated['slots'] = \array_map(fn(array $slot): mixed => $slot['examples'][0] ?? '', $slot_definitions);
    }
    // @phpstan-ignore return.type
    return $hydrated;
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
    // @phpstan-ignore return.type
    return [
      'build' => $this->renderComponent([], $component->getSlotDefinitions(), $component->uuid(), TRUE),
      'metadata' => [
        'slots' => $this->getSlotDefinitions(),
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
    $form[self::INPUT_CONDITION] = [
      '#type' => 'select',
      '#title' => $this->t('Show when'),
      '#description' => $this->t('What is inside is shown only when this is true, and left out entirely when it is not.'),
      '#options' => ViewConditions::options(),
      '#default_value' => $this->conditionFrom($inputValues),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $is_new_instance = $client_model === ['resolved' => []];
    $resolved = $is_new_instance ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    return [self::INPUT_CONDITION => $resolved[self::INPUT_CONDITION] ?? ViewConditions::DEFAULT];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $condition = $inputValues[self::INPUT_CONDITION] ?? ViewConditions::DEFAULT;
    if (!\is_string($condition) || !\array_key_exists($condition, ViewConditions::options())) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Choose a condition.'),
        NULL,
        [],
        $inputValues,
        self::INPUT_CONDITION,
        $condition,
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
        self::INPUT_CONDITION => ['type' => 'string'],
      ],
    ];
  }

}
