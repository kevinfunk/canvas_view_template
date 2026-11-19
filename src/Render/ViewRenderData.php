<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

/**
 * What a View produced, as ViewTemplateRenderer needs it.
 *
 * @see \Drupal\canvas_view_template\Render\ViewRenderDataCollector
 */
final class ViewRenderData {

  /**
   * Creates the data; every piece is optional.
   *
   * @param array|string $rows
   *   The rendered rows (`#rows`); empty when there are no results.
   * @param array|string $exposed
   *   The exposed filter form (`#exposed`).
   * @param array|string $pager
   *   The pager (`#pager`).
   * @param array<int, array|string> $rowRenders
   *   Each row rendered individually.
   * @param int $currentPage
   *   The pager's current page, 0-based.
   * @param array<string, array|string> $areas
   *   The other Views areas (header, footer, empty, title, more, feed_icons,
   *   attachment_before, attachment_after). `empty` is shown by "View no
   *   results", or by "View rows" when the template has no such component.
   * @param array $fieldData
   *   The rows' fields (`rows`) and labels (`labels`).
   * @param array<string, string> $summary
   *   The numbers for "View result summary".
   * @param array<string, bool> $facts
   *   What "View condition" needs to know about the view.
   * @param array<int, string|null> $itemUrls
   *   The page each result item links to.
   */
  public function __construct(
    public readonly array|string $rows = [],
    public readonly array|string $exposed = [],
    public readonly array|string $pager = [],
    public readonly array $rowRenders = [],
    public readonly int $currentPage = 0,
    public readonly array $areas = [],
    public readonly array $fieldData = [],
    public readonly array $summary = [],
    public readonly array $facts = [],
    public readonly array $itemUrls = [],
  ) {}

}
