<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\views\ViewExecutable;

/**
 * Works out the numbers for a "View result summary".
 *
 * Uses the same tokens as the Views "Result summary" area.
 *
 * @see \Drupal\views\Plugin\views\area\Result::render()
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary
 */
final class ViewResultSummary {

  /**
   * The tokens a summary's format can use, with what each means.
   *
   * @return array<string, string>
   *   Descriptions, keyed by token.
   */
  public static function tokens(): array {
    return [
      '@start' => 'the first item shown on this page',
      '@end' => 'the last item shown on this page',
      '@total' => 'the total number of items',
      '@label' => 'the name of the view',
      '@per_page' => 'the number of items per page',
      '@current_page' => 'the current page number',
      '@current_record_count' => 'the number of items on this page',
      '@page_count' => 'the number of pages',
    ];
  }

  /**
   * The values of each token for a View that has been executed.
   *
   * @return array<string, string>
   *   Values keyed by token. Empty if the View has no results to summarize.
   */
  public static function collect(ViewExecutable $view): array {
    $current_page = (int) $view->getCurrentPage() + 1;
    $per_page = (int) $view->getItemsPerPage();
    // Not every pager counts the total: fall back to this page's results.
    $total = (int) ($view->total_rows ?? \count($view->result));
    if ($total === 0) {
      return [];
    }
    if ($per_page === 0) {
      $page_count = 1;
      $start = 1;
      $end = $total;
    }
    else {
      $page_count = (int) \ceil($total / $per_page);
      $start = ($current_page - 1) * $per_page + 1;
      $end = \min($current_page * $per_page, $total);
    }
    return [
      '@start' => (string) $start,
      '@end' => (string) $end,
      '@total' => (string) $total,
      '@label' => (string) $view->storage->label(),
      '@per_page' => (string) $per_page,
      '@current_page' => (string) $current_page,
      '@current_record_count' => (string) ($end - $start + 1),
      '@page_count' => (string) $page_count,
    ];
  }

}
