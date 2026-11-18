<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
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
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View repeater": repeats its slot once per row of a View.
 *
 * Canvas renders a slot once, so the live render is pre-computed by
 * ViewTemplateRenderer (keyed by tree UUID) and the Fiber resumes with it.
 * setSlots() only serves the editor preview.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::renderRepeater()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View repeater'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateRepeater extends ComponentSourceBase implements ComponentSourceWithSlotsInterface {

  use ViewTemplateMarkerPreviewTrait;
  use ViewTemplatePageConditionTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_repeater';

  public const string LOCAL_ID = 'view_repeater';

  /**
   * The single slot's name.
   */
  public const string SLOT_NAME = 'item';

  /**
   * The explicit input key for the maximum number of rows (absent: no limit).
   */
  public const string INPUT_LIMIT = 'limit';

  /**
   * The explicit input key for how many leading rows to skip.
   */
  public const string INPUT_OFFSET = 'offset';

  /**
   * The explicit input key for the page condition mode (ALL, ONLY, EXCEPT).
   */
  public const string INPUT_PAGE_CONDITION_MODE = 'page_condition_mode';

  /**
   * The explicit input key for the 1-based page a page condition refers to.
   */
  public const string INPUT_PAGE_CONDITION_PAGE = 'page_condition_page';

  /**
   * The key hydrateComponent() wraps explicit input under.
   */
  private const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The tree UUID of the instance most recently suspended for rendering.
   *
   * Repeaters share one plugin instance, so the renderer cannot tell them
   * apart by class. Set just before suspending, read just after.
   *
   * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
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
    return new TranslatableMarkup('View repeater');
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    // Not translated: the version hash includes these, so it must not depend
    // on the interface language.
    return [
      self::SLOT_NAME => [
        'title' => 'Item',
        'description' => 'Placed here, once per item — typically a "View item" placeholder.',
        // Non-empty, not `[]`: otherwise setSlots() is never called and the
        // editor has no element to size a drop target against.
        'examples' => [''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // No `#canvas_is_preview`: $build is the live, already-repeated render,
    // and setting the slot would append an extra copy.
    if (empty($build['#canvas_is_preview'])) {
      return;
    }
    $slot_build = $slots[self::SLOT_NAME] ?? [];
    $uuid = $build['#canvas_component_uuid'] ?? NULL;
    if (\is_string($uuid)) {
      // The editor maps a slot to the comments' parent element (the grid).
      $slot_build['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $uuid, self::SLOT_NAME));
      $slot_build['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $uuid, self::SLOT_NAME));
    }
    // A dropped Column's `span` needs a CSS grid parent. The outer container
    // draws the "stack of cards" hint, as pseudo-elements on the grid would
    // become grid items.
    $build[self::SLOT_NAME] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cvt-marker__stack']],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-view-template--slot-grid']],
        'content' => $slot_build,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    // The renderer resumes with this instance's repeated render.
    // @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
    if (\Fiber::getCurrent() !== NULL) {
      $this->currentComponentUuid = $componentUuid;
      $injected = \Fiber::suspend($this);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    // Nothing injected: editor preview; setSlots() fills the `item` key.
    $settings = $inputs[self::EXPLICIT_INPUT_NAME] ?? $this->getDefaultExplicitInput();
    $limit = $settings[self::INPUT_LIMIT] ?? NULL;
    $offset = $settings[self::INPUT_OFFSET] ?? 0;
    $chips = [$this->rowsChip($limit === NULL ? NULL : (int) $limit, (int) $offset)];
    $page_condition_mode = $settings[self::INPUT_PAGE_CONDITION_MODE] ?? self::PAGE_CONDITION_ALL;
    $page_condition_page = $settings[self::INPUT_PAGE_CONDITION_PAGE] ?? NULL;
    if ($page_condition_page !== NULL) {
      $chips[] = match ($page_condition_mode) {
        self::PAGE_CONDITION_ONLY => $this->t('Page @page only', ['@page' => $page_condition_page]),
        self::PAGE_CONDITION_EXCEPT => $this->t('Not on page @page', ['@page' => $page_condition_page]),
        default => NULL,
      };
    }
    $build = $this->buildMarkerStylePreview('repeater', $this->t('View repeater'), $this->t('Lay out each item yourself: repeats its contents once per item.'), \array_values(\array_filter($chips)));
    $build[self::SLOT_NAME] = [];
    // Consumed by ::setSlots().
    $build['#canvas_component_uuid'] = $componentUuid;
    $build['#canvas_is_preview'] = $isPreview;
    return $build;
  }

  /**
   * Summarises the covered rows for the preview chip.
   */
  private function rowsChip(?int $limit, int $offset): TranslatableMarkup {
    $first = $offset + 1;
    return match (TRUE) {
      $limit === NULL && $offset === 0 => $this->t('All items'),
      $limit === NULL => $this->t('From item @first', ['@first' => $first]),
      $limit === 1 => $this->t('Item @first', ['@first' => $first]),
      default => $this->t('Items @first–@last', ['@first' => $first, '@last' => $offset + $limit]),
    };
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
    // Limit and page are omitted, not NULL: the schema marks them
    // `requiredKey: false` and a present NULL fails validation.
    // @phpstan-ignore return.type
    return [
      self::INPUT_OFFSET => 0,
      self::INPUT_PAGE_CONDITION_MODE => self::PAGE_CONDITION_ALL,
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
    return $form + $this->buildLimitOffsetPageConditionFormElements(
      self::INPUT_LIMIT,
      self::INPUT_OFFSET,
      self::INPUT_PAGE_CONDITION_MODE,
      self::INPUT_PAGE_CONDITION_PAGE,
      $inputValues,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    // @phpstan-ignore identical.alwaysFalse
    $is_new_instance = $client_model === ['resolved' => []];
    // A newly-placed instance sends an empty `resolved`.
    // @phpstan-ignore nullCoalesce.offset, ternary.alwaysFalse
    $resolved = $is_new_instance ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    return $this->limitOffsetPageConditionToInput(
      $resolved,
      self::INPUT_LIMIT,
      self::INPUT_OFFSET,
      self::INPUT_PAGE_CONDITION_MODE,
      self::INPUT_PAGE_CONDITION_PAGE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $this->validateLimitOffsetPageCondition(
      $inputValues,
      self::INPUT_LIMIT,
      self::INPUT_OFFSET,
      self::INPUT_PAGE_CONDITION_MODE,
      self::INPUT_PAGE_CONDITION_PAGE,
      $violations,
    );
    return $violations;
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
    return [
      'type' => 'mapping',
      'mapping' => [
        self::INPUT_LIMIT => ['type' => 'integer', 'requiredKey' => FALSE],
        self::INPUT_OFFSET => ['type' => 'integer'],
        self::INPUT_PAGE_CONDITION_MODE => ['type' => 'string'],
        self::INPUT_PAGE_CONDITION_PAGE => ['type' => 'integer', 'requiredKey' => FALSE],
      ],
    ];
  }

}
