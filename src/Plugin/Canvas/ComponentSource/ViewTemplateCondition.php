<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
final class ViewTemplateCondition extends ViewTemplateComponentSourceBase implements ComponentSourceWithSlotsInterface {

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
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View condition');
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
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
    $this->setFlaggedSlot($build, $slots, self::SLOT_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    if (\Fiber::getCurrent() !== NULL) {
      $injected = $this->suspendFor($componentUuid);
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
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
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
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $condition = $inputValues[self::INPUT_CONDITION] ?? ViewConditions::DEFAULT;
    if (!\is_string($condition) || !\array_key_exists($condition, ViewConditions::options())) {
      $violations->add($this->violation($this->t('Choose a condition.'), $inputValues, self::INPUT_CONDITION, $condition));
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
        self::INPUT_CONDITION => ['type' => 'string'],
      ],
    ];
  }

}
