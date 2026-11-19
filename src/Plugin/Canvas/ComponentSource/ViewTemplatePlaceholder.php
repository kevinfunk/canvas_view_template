<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\Core\StringTranslation\TranslatableMarkup;

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
final class ViewTemplatePlaceholder extends ViewTemplateComponentSourceBase {

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_placeholder';

  /**
   * The Library folder these components (and the repeater) sit in.
   */
  public const string LIBRARY_FOLDER_NAME = 'View template';

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
   * The kind, title and caption of this placeholder.
   *
   * @return array{0: string, 1: \Drupal\Core\StringTranslation\TranslatableMarkup, 2: \Drupal\Core\StringTranslation\TranslatableMarkup|string}
   *   The kind (picks the icon), title and caption.
   */
  private function describe(): array {
    \assert(\is_string($this->configuration['local_source_id']));
    return match ($this->configuration['local_source_id']) {
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
      default => [
        'rows',
        $this->t('View template placeholder'),
        '',
      ],
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return $this->describe()[1];
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
      [$kind, $title, $caption] = $this->describe();
      return $this->buildMarkerStylePreview($kind, $title, $caption);
    }
    return [];
  }

}
