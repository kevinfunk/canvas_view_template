<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View no results", shown only when the View has no results.
 *
 * An empty slot falls back to the Views UI "no results" content. The renderer
 * resumes the Fiber with `[]` (results exist), the Views empty area (no
 * results, empty slot), or NULL (no results: render the slot via setSlots()).
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View no results'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateEmpty extends ComponentSourceBase implements ComponentSourceWithSlotsInterface {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_empty';

  public const string LOCAL_ID = 'view_empty';

  /**
   * The slot name for the "no results" content.
   */
  public const string SLOT_NAME = 'content';

  /**
   * The tree UUID of the instance most recently suspended for rendering.
   *
   * Set just before suspending and read just after; see
   * ViewTemplateRepeater::$currentComponentUuid.
   */
  protected ?string $currentComponentUuid = NULL;

  /**
   * Gets the tree UUID of the instance most recently suspended.
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
    return new TranslatableMarkup('View no results');
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
        'description' => 'Shown only when the view has no results.',
        // Non-empty so the editor draws a drop target (as in the repeater).
        'examples' => [''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // With results the renderer resumes with `[]`, which has no flag.
    if (empty($build['#cvt_show_slot'])) {
      return;
    }
    $slot_build = $slots[self::SLOT_NAME] ?? [];
    if (empty($build['#canvas_is_preview'])) {
      $build[self::SLOT_NAME] = $slot_build;
      return;
    }
    // The editor's slot comments need a real wrapper element.
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
      $build = $this->buildMarkerStylePreview(
        'empty',
        $this->t('View no results'),
        $this->t('Shown only when the view has no results.'),
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
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    // The `slots` key makes Canvas call ::setSlots().
    $hydrated = [];
    if (!empty($slot_definitions)) {
      $hydrated['slots'] = \array_map(fn(array $slot): mixed => $slot['examples'][0] ?? '', $slot_definitions);
    }
    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
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
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    // Always available.
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
    return [];
  }

}
