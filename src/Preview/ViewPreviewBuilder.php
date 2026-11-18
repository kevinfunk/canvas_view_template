<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Preview;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\Render\ViewFieldRenders;
use Drupal\canvas_view_template\Render\ViewResultSummary;
use Drupal\canvas_view_template\Render\ViewItemUrls;
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
   * @return array{rows: array|string, exposed: array|string, pager: array|string, row_renders: array, field_data: array, summary: array, facts: array<string, bool>, item_urls: array<int, string|null>, current_page: int, areas: array, cacheability: \Drupal\Core\Cache\CacheableMetadata}|null
   *   The pieces to render a view template with, or NULL.
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

    $areas = [];
    foreach (['header', 'footer', 'empty', 'more', 'feed_icons', 'attachment_before', 'attachment_after'] as $key) {
      if (!empty($element["#$key"])) {
        $areas[$key] = $element["#$key"];
      }
    }
    $title = $view->getTitle();
    if (\is_string($title) && $title !== '') {
      $areas['title'] = ['#plain_text' => $title];
    }

    $row_renders = [];
    if ($view->rowPlugin) {
      foreach ($view->result as $row) {
        $row_renders[] = $view->rowPlugin->render($row);
      }
    }

    $cacheability = CacheableMetadata::createFromRenderArray($element)
      ->addCacheableDependency($view->storage);
    $item_urls = ViewItemUrls::collect($view, $cacheability);
    return [
      'rows' => $element['#rows'] ?? [],
      'exposed' => $element['#exposed'] ?? [],
      'pager' => $element['#pager'] ?? [],
      'row_renders' => $row_renders,
      'field_data' => ViewFieldRenders::collect($view),
      'summary' => ViewResultSummary::collect($view),
      'facts' => ViewConditions::collect($view),
      'item_urls' => $item_urls,
      'current_page' => $view->getCurrentPage() ?? 0,
      'areas' => $areas,
      'cacheability' => $cacheability,
    ];
  }

}
