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
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View item link": wraps its content in a link to the current item.
 *
 * An item with no page is shown without a link. The content should not hold
 * links of its own (nested links are invalid HTML). The renderer resumes the
 * Fiber with `['url' => <the item's page, or NULL>]`; ::setSlots() wraps
 * the slot.
 *
 * @see \Drupal\canvas_view_template\Render\ViewItemUrls
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View item link'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateItemLink extends ComponentSourceBase implements ComponentSourceWithSlotsInterface {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_item_link';

  public const string LOCAL_ID = 'view_item_link';

  /**
   * The name of the slot the linked content goes in.
   */
  public const string SLOT_NAME = 'content';

  /**
   * The explicit input key for where the link opens.
   */
  public const string INPUT_OPEN = 'open';

  public const string OPEN_SAME = 'same';

  public const string OPEN_NEW = 'new';

  /**
   * The key `hydrateComponent()` wraps explicit input under.
   */
  private const string EXPLICIT_INPUT_NAME = 'settings';

  /**
   * The allowed "Open in" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by value.
   */
  public static function openOptions(): array {
    return [
      self::OPEN_SAME => new TranslatableMarkup('Same tab'),
      self::OPEN_NEW => new TranslatableMarkup('New tab'),
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
    return new TranslatableMarkup('View item link');
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
        'description' => "Linked to the current item's page.",
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
    $slot_build = $slots[self::SLOT_NAME] ?? [];
    if (empty($build['#canvas_is_preview'])) {
      $url = $build['#cvt_link_url'] ?? NULL;
      if (!\is_string($url) || $url === '') {
        $build[self::SLOT_NAME] = $slot_build;
        return;
      }
      $attributes = ['href' => $url];
      if (!empty($build['#cvt_link_new_tab'])) {
        $attributes['target'] = '_blank';
        $attributes['rel'] = 'noopener';
      }
      $build[self::SLOT_NAME] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#attributes' => $attributes,
        'content' => $slot_build,
      ];
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
    $open = $this->openFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);

    if (\Fiber::getCurrent() !== NULL) {
      $injected = \Fiber::suspend($this);
      if (\is_array($injected)) {
        return [
          '#cvt_link_url' => $injected['url'] ?? NULL,
          '#cvt_link_new_tab' => $open === self::OPEN_NEW,
          self::SLOT_NAME => [],
        ];
      }
    }
    if ($isPreview) {
      $build = $this->buildMarkerStylePreview(
        'link',
        $this->t('View item link'),
        $this->t('Links what is inside to the current item. Put content without links of its own inside.'),
        [self::openOptions()[$open]],
      );
      $build[self::SLOT_NAME] = [];
      $build['#canvas_component_uuid'] = $componentUuid;
      $build['#canvas_is_preview'] = TRUE;
      return $build;
    }
    return [self::SLOT_NAME => []];
  }

  /**
   * Returns a valid "Open in" value from stored settings, or the default.
   */
  private function openFrom(array $settings): string {
    $open = $settings[self::INPUT_OPEN] ?? self::OPEN_SAME;
    return \is_string($open) && \array_key_exists($open, self::openOptions()) ? $open : self::OPEN_SAME;
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
    return [self::INPUT_OPEN => self::OPEN_SAME];
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
    $form[self::INPUT_OPEN] = [
      '#type' => 'select',
      '#title' => $this->t('Open in'),
      '#description' => $this->t('Where the link opens. Content inside should not contain links of its own.'),
      '#options' => self::openOptions(),
      '#default_value' => $this->openFrom($inputValues),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $is_new_instance = $client_model === ['resolved' => []];
    $resolved = $is_new_instance ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    return [self::INPUT_OPEN => $resolved[self::INPUT_OPEN] ?? self::OPEN_SAME];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $open = $inputValues[self::INPUT_OPEN] ?? self::OPEN_SAME;
    if (!\is_string($open) || !\array_key_exists($open, self::openOptions())) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Choose where the link opens.'),
        NULL,
        [],
        $inputValues,
        self::INPUT_OPEN,
        $open,
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
        self::INPUT_OPEN => ['type' => 'string'],
      ],
    ];
  }

}
