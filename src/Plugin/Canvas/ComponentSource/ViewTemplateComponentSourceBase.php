<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * What this module's component sources have in common.
 *
 * The defaults suit a component without settings. A component with settings
 * returns TRUE from ::requiresExplicitInput() and lists them in
 * ::getDefaultExplicitInput(); the stored shape then follows from that.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
abstract class ViewTemplateComponentSourceBase extends ComponentSourceBase {

  use ViewTemplateMarkerPreviewTrait;

  /**
   * The key `hydrateComponent()` wraps explicit input under.
   */
  protected const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The tree UUID of the instance most recently suspended for rendering.
   *
   * Instances share one plugin object, so the renderer cannot tell them apart
   * by class. Set just before suspending, read just after.
   */
  protected ?string $currentComponentUuid = NULL;

  /**
   * Gets the tree UUID of the instance most recently suspended.
   */
  public function getCurrentComponentUuid(): ?string {
    return $this->currentComponentUuid;
  }

  /**
   * Suspends the Fiber, recording which instance is waiting.
   *
   * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
   */
  protected function suspendFor(string $componentUuid): mixed {
    $this->currentComponentUuid = $componentUuid;
    return \Fiber::suspend($this);
  }

  /**
   * Adds the slot-marker comments the editor maps a slot by.
   *
   * @param array $slot_build
   *   The slot's render array.
   * @param mixed $uuid
   *   The instance's tree UUID; nothing is added unless it is a string.
   * @param string $slot
   *   The slot name.
   *
   * @return array
   *   The slot's render array with the markers.
   */
  protected function markSlot(array $slot_build, mixed $uuid, string $slot): array {
    if (\is_string($uuid)) {
      $slot_build['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $uuid, $slot));
      $slot_build['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $uuid, $slot));
    }
    return $slot_build;
  }

  /**
   * Fills the `content` slot of a component that shows it only when flagged.
   *
   * Without `#cvt_show_slot` the renderer resumed the Fiber with `[]`.
   */
  protected function setFlaggedSlot(array &$build, array $slots, string $slot): void {
    if (empty($build['#cvt_show_slot'])) {
      return;
    }
    $slot_build = $slots[$slot] ?? [];
    if (empty($build['#canvas_is_preview'])) {
      $build[$slot] = $slot_build;
      return;
    }
    // The editor's slot comments need a real wrapper element.
    $build[$slot] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cvt-marker__slot']],
      'content' => $this->markSlot($slot_build, $build['#canvas_component_uuid'] ?? NULL, $slot),
    ];
  }

  /**
   * Builds a violation for one setting.
   */
  protected function violation(TranslatableMarkup|string $message, array $root, string $path, mixed $value): ConstraintViolation {
    return new ConstraintViolation((string) $message, NULL, [], $root, $path, $value);
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
    if (!$this->requiresExplicitInput()) {
      return [];
    }
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
    $hydrated = [];
    if ($this->requiresExplicitInput()) {
      $hydrated[self::EXPLICIT_INPUT_NAME] = $explicit_input + $this->getDefaultExplicitInput();
    }
    // The `slots` key is what makes Canvas call ::setSlots().
    if (!empty($slot_definitions)) {
      $hydrated['slots'] = \array_map(fn(array $slot): mixed => $slot['examples'][0] ?? '', $slot_definitions);
    }
    // The interface's array shape is narrower than the `slots` key we add.
    // @phpstan-ignore return.type
    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => $this->requiresExplicitInput() ? $explicit_input + $this->getDefaultExplicitInput() : []];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    // The interface's array shape does not describe the client-side info.
    // @phpstan-ignore return.type
    return [
      'build' => $this->renderComponent([], $component->getSlotDefinitions(), $component->uuid(), TRUE),
      'metadata' => [
        'slots' => $this instanceof ComponentSourceWithSlotsInterface ? $this->getSlotDefinitions() : [],
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
    if (!$this->requiresExplicitInput()) {
      return [];
    }
    $defaults = $this->getDefaultExplicitInput();
    // A newly placed instance sends an empty `resolved`.
    $resolved = $client_model === ['resolved' => []] ? $defaults : ($client_model['resolved'] ?? []);
    $input = [];
    foreach ($defaults as $key => $default) {
      $input[$key] = $resolved[$key] ?? $default;
    }
    return $input;
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
