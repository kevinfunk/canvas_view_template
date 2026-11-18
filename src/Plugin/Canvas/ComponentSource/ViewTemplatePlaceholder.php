<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides the placeholder components a view template is built from.
 *
 * Each suspends a Fiber that ViewTemplateRenderer resumes with the View's
 * real output. Not built on Canvas's `marker` source, because the editor
 * hides markers from the Library and disables their actions.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View template placeholder'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplatePlaceholder extends ComponentSourceBase {

  use ViewTemplateMarkerPreviewTrait;

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_placeholder';

  public const string VIEW_ROWS_LOCAL_ID = 'view_rows';

  public const string VIEW_EXPOSED_FILTERS_LOCAL_ID = 'view_exposed_filters';

  public const string VIEW_PAGER_LOCAL_ID = 'view_pager';

  public const string VIEW_HEADER_LOCAL_ID = 'view_header';

  public const string VIEW_FOOTER_LOCAL_ID = 'view_footer';

  public const string VIEW_MORE_LOCAL_ID = 'view_more';

  public const string VIEW_ATTACHMENTS_LOCAL_ID = 'view_attachments';

  /**
   * The local ID of "View item".
   *
   * Meant for a "View repeater" slot; elsewhere it shows the first row.
   */
  public const string VIEW_ITEM_LOCAL_ID = 'view_item';

  /**
   * Gets which placeholder this instance is.
   */
  protected function getLocalId(): string {
    \assert(\is_string($this->configuration['local_source_id']));
    return $this->configuration['local_source_id'];
  }

  /**
   * The Library folder these components (and the repeater) sit in.
   */
  public const string LIBRARY_FOLDER_NAME = 'View template';

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
    return self::LIBRARY_FOLDER_NAME;
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
    return match ($this->getLocalId()) {
      self::VIEW_ROWS_LOCAL_ID => new TranslatableMarkup('View rows'),
      self::VIEW_EXPOSED_FILTERS_LOCAL_ID => new TranslatableMarkup('View exposed filters'),
      self::VIEW_PAGER_LOCAL_ID => new TranslatableMarkup('View pager'),
      self::VIEW_ITEM_LOCAL_ID => new TranslatableMarkup('View item'),
      self::VIEW_HEADER_LOCAL_ID => new TranslatableMarkup('View header'),
      self::VIEW_FOOTER_LOCAL_ID => new TranslatableMarkup('View footer'),
      self::VIEW_MORE_LOCAL_ID => new TranslatableMarkup('View more link'),
      self::VIEW_ATTACHMENTS_LOCAL_ID => new TranslatableMarkup('View attachments'),
      default => new TranslatableMarkup('View template placeholder'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    // The renderer resumes with the matching View output.
    // @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
    if (\Fiber::getCurrent() !== NULL) {
      $injected = \Fiber::suspend($this);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    // Nothing injected: the editor preview.
    if ($isPreview) {
      [$kind, $title, $caption] = match ($this->getLocalId()) {
        self::VIEW_ROWS_LOCAL_ID => [
          'rows',
          $this->t('View rows'),
          $this->t('The items, as the view formats them.'),
        ],
        self::VIEW_EXPOSED_FILTERS_LOCAL_ID => [
          'filters',
          $this->t('View exposed filters'),
          $this->t('The exposed filter form appears here.'),
        ],
        self::VIEW_PAGER_LOCAL_ID => [
          'pager',
          $this->t('View pager'),
          $this->t('The pager appears here.'),
        ],
        self::VIEW_ITEM_LOCAL_ID => [
          'item',
          $this->t('View item'),
          $this->t('One whole item as the view renders it. Place it inside a View repeater.'),
        ],
        self::VIEW_HEADER_LOCAL_ID => [
          'header',
          $this->t('View header'),
          $this->t("The view's header appears here. Add one in the view's Header settings."),
        ],
        self::VIEW_FOOTER_LOCAL_ID => [
          'footer',
          $this->t('View footer'),
          $this->t("The view's footer appears here. Add one in the view's Footer settings."),
        ],
        self::VIEW_MORE_LOCAL_ID => [
          'more',
          $this->t('View more link'),
          $this->t("The view's \"more\" link appears here. Turn it on in the view's Pager settings."),
        ],
        self::VIEW_ATTACHMENTS_LOCAL_ID => [
          'attachments',
          $this->t('View attachments'),
          $this->t("The view's attachments appear here. Add an Attachment display to the view and attach it to this display."),
        ],
        default => ['rows', $this->t('View template placeholder'), ''],
      };
      return $this->buildMarkerStylePreview($kind, $title, $caption);
    }
    return [];
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
    return [];
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
    // @phpstan-ignore return.type
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
