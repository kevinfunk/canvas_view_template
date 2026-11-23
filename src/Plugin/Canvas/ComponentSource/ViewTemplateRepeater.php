<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
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
final class ViewTemplateRepeater extends ViewTemplateComponentSourceBase implements ComponentSourceWithSlotsInterface {

  use ViewTemplateMarkerPreviewTrait;
  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_repeater';

  public const string LOCAL_ID = 'view_repeater';

  public const string PAGE_CONDITION_ALL = 'all';

  public const string PAGE_CONDITION_ONLY = 'only';

  public const string PAGE_CONDITION_EXCEPT = 'except';

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
   * Reads stored settings as safe values, whatever was stored.
   *
   * Config imports and the API can store strings or junk that validation
   * never saw, and the renderer must not throw on them.
   *
   * @return array{limit: int|null, offset: int, mode: string, page: int|null}
   *   A NULL limit means no limit (0 too), a NULL page no page condition.
   */
  public static function normalizeSettings(mixed $settings): array {
    $settings = \is_array($settings) ? $settings : [];
    $limit = self::wholeNumber($settings[self::INPUT_LIMIT] ?? NULL);
    $page = self::wholeNumber($settings[self::INPUT_PAGE_CONDITION_PAGE] ?? NULL);
    $mode = $settings[self::INPUT_PAGE_CONDITION_MODE] ?? NULL;
    return [
      'limit' => $limit !== NULL && $limit > 0 ? $limit : NULL,
      'offset' => \max(0, self::wholeNumber($settings[self::INPUT_OFFSET] ?? NULL) ?? 0),
      'mode' => \in_array($mode, [self::PAGE_CONDITION_ONLY, self::PAGE_CONDITION_EXCEPT], TRUE) ? $mode : self::PAGE_CONDITION_ALL,
      'page' => $page !== NULL && $page >= 1 ? $page : NULL,
    ];
  }

  /**
   * An integer, or a string or float that holds exactly one; else NULL.
   */
  private static function wholeNumber(mixed $value): ?int {
    return match (TRUE) {
      \is_int($value) => $value,
      \is_string($value) && \preg_match('/^\s*-?\d+\s*$/', $value) === 1 => (int) $value,
      \is_float($value) && \is_finite($value) && $value === \floor($value) => (int) $value,
      default => NULL,
    };
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
    // The editor maps a slot to the comments' parent element (the grid).
    $slot_build = $this->markSlot($slot_build, $build['#canvas_component_uuid'] ?? NULL, self::SLOT_NAME);
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
      $injected = $this->suspendFor($componentUuid);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    if (!$isPreview) {
      return [];
    }
    // Nothing injected: editor preview; setSlots() fills the `item` key.
    $settings = self::normalizeSettings($inputs[self::EXPLICIT_INPUT_NAME] ?? $this->getDefaultExplicitInput());
    $chips = [$this->rowsChip($settings['limit'], $settings['offset'])];
    if ($settings['page'] !== NULL) {
      $chips[] = match ($settings['mode']) {
        self::PAGE_CONDITION_ONLY => $this->t('Page @page only', ['@page' => $settings['page']]),
        self::PAGE_CONDITION_EXCEPT => $this->t('Not on page @page', ['@page' => $settings['page']]),
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
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $form[self::INPUT_LIMIT] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items'),
      '#description' => $this->t('How many items to repeat. Enter 0 or leave empty for no limit.'),
      '#min' => 0,
      '#default_value' => $inputValues[self::INPUT_LIMIT] ?? NULL,
    ];
    $form[self::INPUT_OFFSET] = [
      '#type' => 'number',
      '#title' => $this->t('Offset'),
      '#description' => $this->t('How many leading items to skip before repeating starts.'),
      '#min' => 0,
      '#default_value' => $inputValues[self::INPUT_OFFSET] ?? 0,
    ];
    $form[self::INPUT_PAGE_CONDITION_MODE] = [
      '#type' => 'select',
      '#title' => $this->t('Show on'),
      '#description' => $this->t('Which pager page(s) this is active on — unlike "Offset" above, which only slices items within whichever page is currently showing, this applies across pagination.'),
      '#options' => [
        self::PAGE_CONDITION_ALL => $this->t('All pages'),
        self::PAGE_CONDITION_ONLY => $this->t('Only page'),
        self::PAGE_CONDITION_EXCEPT => $this->t('All pages except'),
      ],
      '#default_value' => $inputValues[self::INPUT_PAGE_CONDITION_MODE] ?? self::PAGE_CONDITION_ALL,
    ];
    // No #states: it needs a custom #name, which Canvas's client form
    // serialization ignores, so the selection would not be saved.
    $form[self::INPUT_PAGE_CONDITION_PAGE] = [
      '#type' => 'number',
      '#title' => $this->t('Page number'),
      '#description' => $this->t("The pager's page number, starting at 1 for the first page. Only used when \"Show on\" above is set to \"Only page\" or \"All pages except\"."),
      '#min' => 1,
      '#default_value' => $inputValues[self::INPUT_PAGE_CONDITION_PAGE] ?? NULL,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    // A newly placed instance sends an empty `resolved`.
    $resolved = $client_model === ['resolved' => []] ? $this->getDefaultExplicitInput() : ($client_model['resolved'] ?? []);
    // Limit and page are omitted when unset, as `requiredKey: false` requires.
    $input = [
      self::INPUT_OFFSET => (int) ($resolved[self::INPUT_OFFSET] ?? 0),
      self::INPUT_PAGE_CONDITION_MODE => $resolved[self::INPUT_PAGE_CONDITION_MODE] ?? self::PAGE_CONDITION_ALL,
    ];
    foreach ([self::INPUT_LIMIT, self::INPUT_PAGE_CONDITION_PAGE] as $key) {
      $value = $resolved[$key] ?? NULL;
      if ($value !== NULL && $value !== '') {
        $input[$key] = (int) $value;
      }
    }
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $limit = $inputValues[self::INPUT_LIMIT] ?? NULL;
    if ($limit !== NULL && !\is_int($limit)) {
      $violations->add($this->violation($this->t('Number of items must be a whole number.'), $inputValues, self::INPUT_LIMIT, $limit));
    }
    elseif ($limit !== NULL && $limit < 0) {
      $violations->add($this->violation($this->t('Number of items must be 0 or greater.'), $inputValues, self::INPUT_LIMIT, $limit));
    }
    $offset = $inputValues[self::INPUT_OFFSET] ?? 0;
    if (!\is_int($offset)) {
      $violations->add($this->violation($this->t('Offset must be a whole number.'), $inputValues, self::INPUT_OFFSET, $offset));
    }
    elseif ($offset < 0) {
      $violations->add($this->violation($this->t('Offset must be 0 or greater.'), $inputValues, self::INPUT_OFFSET, $offset));
    }
    $mode = $inputValues[self::INPUT_PAGE_CONDITION_MODE] ?? self::PAGE_CONDITION_ALL;
    if (!\in_array($mode, [self::PAGE_CONDITION_ALL, self::PAGE_CONDITION_ONLY, self::PAGE_CONDITION_EXCEPT], TRUE)) {
      $violations->add($this->violation($this->t('Choose where the repeater shows: all pages, only a page, or all pages except one.'), $inputValues, self::INPUT_PAGE_CONDITION_MODE, $mode));
    }
    $page = $inputValues[self::INPUT_PAGE_CONDITION_PAGE] ?? NULL;
    if ($page !== NULL && !\is_int($page)) {
      $violations->add($this->violation($this->t('Page number must be a whole number.'), $inputValues, self::INPUT_PAGE_CONDITION_PAGE, $page));
    }
    elseif (\in_array($mode, [self::PAGE_CONDITION_ONLY, self::PAGE_CONDITION_EXCEPT], TRUE) && ($page === NULL || $page < 1)) {
      $violations->add($this->violation($this->t('A page number is required when "Show on" is set to "Only page" or "All pages except".'), $inputValues, self::INPUT_PAGE_CONDITION_PAGE, $page));
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
        self::INPUT_LIMIT => ['type' => 'integer', 'requiredKey' => FALSE],
        self::INPUT_OFFSET => ['type' => 'integer'],
        self::INPUT_PAGE_CONDITION_MODE => ['type' => 'string'],
        self::INPUT_PAGE_CONDITION_PAGE => ['type' => 'integer', 'requiredKey' => FALSE],
      ],
    ];
  }

}
