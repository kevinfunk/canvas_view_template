<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Preview;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\canvas_view_template\Render\ViewRenderDataCollector;
use Drupal\views\Views;

/**
 * Runs a View and collects the pieces a view template needs to render it.
 *
 * Collects the same pieces Views' own template receives (rows, exposed
 * filters, pager, areas) without going through a theme hook, so the template
 * being edited renders them whichever template the View itself uses.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 */
final class ViewPreviewBuilder {

  /**
   * Builds the pieces for a View display, or NULL if it can't be previewed.
   *
   * @param string $view_id
   *   The View.
   * @param string $display_id
   *   The display.
   * @param bool $count_total
   *   Whether to count all results even with a pager that does not.
   *
   * @return array{data: \Drupal\canvas_view_template\Render\ViewRenderData, cacheability: \Drupal\Core\Cache\CacheableMetadata}|null
   *   The data to render a view template with, and its cacheability, or NULL.
   */
  public function build(string $view_id, string $display_id, bool $count_total = FALSE): ?array {
    $view = Views::getView($view_id);
    if ($view === NULL || !$view->setDisplay($display_id) || !$view->access($display_id)) {
      return NULL;
    }
    if (!$view->display_handler->isEnabled()) {
      return NULL;
    }
    if ($count_total) {
      $view->get_total_rows = TRUE;
    }
    // A normal render, so every preRender() the rows depend on runs.
    $view->preExecute();
    $view->execute($display_id);
    $element = $view->render($display_id);
    if (!\is_array($element)) {
      return NULL;
    }
    $element = $view->display_handler->elementPreRender($element);

    $cacheability = CacheableMetadata::createFromRenderArray($element)
      ->addCacheableDependency($view->storage);
    $data = ViewRenderDataCollector::collect($view, $element, '#', NULL, $cacheability);
    return ['data' => $data, 'cacheability' => $cacheability];
  }

}
