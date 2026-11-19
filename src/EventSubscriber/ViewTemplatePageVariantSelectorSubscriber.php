<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\EventSubscriber;

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\AdminContext;
use Drupal\views\Views;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies a Views display's own "Page template" override, if set.
 *
 * Runs after Canvas's PageVariantSelectorSubscriber (which picks the site
 * default) and only changes the result for Views page routes using
 * ViewTemplateStyle with a "Page template" option.
 *
 * @see \Drupal\canvas\EventSubscriber\PageVariantSelectorSubscriber
 */
final class ViewTemplatePageVariantSelectorSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AdminContext $adminContext,
    protected readonly ViewTemplatePreviewSelection $previewSelection,
  ) {}

  /**
   * Reacts to core's page-display-variant selection event.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $route_match = $event->getRouteMatch();
    $route = $route_match->getRouteObject();
    $preview_template = $route_match->getParameter('entity');
    if ($preview_template instanceof ViewTemplate && \str_starts_with((string) $route_match->getRouteName(), 'canvas.api.layout.')) {
      // The editor's preview: use the page template of the View display it
      // is previewed with. These are admin routes, so this must come first.
      $choice = $this->previewSelection->get((string) $preview_template->id());
      if ($choice === NULL) {
        return;
      }
      $view_id = $choice['view'];
      $display_id = $choice['display'];
    }
    else {
      // Like PageVariantSelectorSubscriber: front-end pages only.
      if ($this->adminContext->isAdminRoute($route)) {
        return;
      }
      $view_id = $route?->getDefault('view_id');
      $display_id = $route?->getDefault('display_id');
    }
    if (!\is_string($view_id) || !\is_string($display_id)) {
      return;
    }
    // Instantiating the View handles display "defaults" inheritance, which
    // raw config would not.
    $view = Views::getView($view_id);
    // Checked first: setDisplay() logs a warning for a missing display.
    if ($view === NULL || !isset($view->storage->get('display')[$display_id]) || !$view->setDisplay($display_id)) {
      return;
    }
    $event->addCacheableDependency($view->storage);

    $options = ViewTemplateStyle::displayOptions($view);
    if ($options === NULL) {
      return;
    }
    $override = $options[ViewTemplateStyle::PAGE_VARIANT_OPTION_KEY] ?? NULL;
    if ($override === NULL || $override === '') {
      // Unset: keep the site default.
      return;
    }

    if ($override === ViewTemplateStyle::NO_PAGE_VARIANT_OPTION_VALUE) {
      $event->setPluginId('block_page');
      $event->setPluginConfiguration([]);
      return;
    }

    $variant = $this->entityTypeManager->getStorage(PageVariant::ENTITY_TYPE_ID)->load($override);
    // Even when it is not used, so restoring it refreshes cached pages.
    $event->addCacheableDependency($variant instanceof PageVariant
      ? $variant
      : (new CacheableMetadata())->addCacheTags($this->entityTypeManager->getDefinition(PageVariant::ENTITY_TYPE_ID)->getListCacheTags()));
    if (!$variant instanceof PageVariant || !$variant->status()) {
      // Stale reference: keep whatever was already selected.
      return;
    }
    $event->setPluginId(CanvasPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([
      // Views routes never set `_canvas_use_template_draft`, so this is
      // always the published variant.
      CanvasPageVariant::PREVIEW_KEY => FALSE,
      CanvasPageVariant::VARIANT_ID_KEY => $variant->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RenderEvents::SELECT_PAGE_DISPLAY_VARIANT][] = ['onSelectPageDisplayVariant', -200];
    return $events;
  }

}
