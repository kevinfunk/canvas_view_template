<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\views\ViewExecutable;

/**
 * Collects a rendered View's pieces for ViewTemplateRenderer.
 *
 * Shared by the front end, the editor preview and the live preview.
 */
final class ViewRenderDataCollector {

  private const array AREAS = [
    'header',
    'footer',
    'empty',
    'more',
    'feed_icons',
    'attachment_before',
    'attachment_after',
  ];

  /**
   * Collects the pieces of an executed and rendered View.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The View, already rendered.
   * @param array $element
   *   The View's render array or theme variables.
   * @param string $prefix
   *   The key prefix in $element: `#` for a render array, none for variables.
   * @param string[]|null $used
   *   The component ids the template places, to skip data nobody uses; NULL
   *   collects everything.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Gets what the collection depends on added.
   */
  public static function collect(ViewExecutable $view, array $element, string $prefix, ?array $used, CacheableMetadata $cacheability): ViewRenderData {
    $uses = static fn (string ...$ids): bool => $used === NULL || \array_intersect($ids, $used) !== [];

    $areas = [];
    foreach (self::AREAS as $key) {
      if (!empty($element[$prefix . $key])) {
        $areas[$key] = $element[$prefix . $key];
      }
    }
    // The `title` element is always '' here; getTitle() includes
    // contextual-filter overrides.
    $title = $view->getTitle();
    if (\is_string($title) && $title !== '') {
      $areas['title'] = ['#plain_text' => $title];
    }

    // Per-row rendering duplicates Views' own work; only do it when needed.
    $needs_rows = $uses(ViewTemplateRenderer::VIEW_REPEATER_COMPONENT_ID, ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID);
    return new ViewRenderData(
      rows: $element[$prefix . 'rows'] ?? [],
      exposed: $element[$prefix . 'exposed'] ?? [],
      pager: $element[$prefix . 'pager'] ?? [],
      rowRenders: $needs_rows ? self::renderEachRow($view) : [],
      currentPage: $view->getCurrentPage() ?? 0,
      areas: $areas,
      fieldData: $uses(ViewTemplateRenderer::VIEW_FIELD_COMPONENT_ID) ? ViewFieldRenders::collect($view) : [],
      summary: $uses(ViewTemplateRenderer::VIEW_SUMMARY_COMPONENT_ID) ? ViewResultSummary::collect($view) : [],
      facts: $uses(ViewTemplateRenderer::VIEW_CONDITION_COMPONENT_ID) ? ViewConditions::collect($view) : [],
      itemUrls: $uses(ViewTemplateRenderer::VIEW_ITEM_LINK_COMPONENT_ID) ? ViewItemUrls::collect($view, $cacheability) : [],
    );
  }

  /**
   * Renders each of a View's result rows individually.
   *
   * `#rows` combines all rows; repeaters need each one separately.
   *
   * @return array<int, array>
   *   Each result row's render array, in display order.
   *
   * @see \Drupal\views\Plugin\views\row\RowPluginBase::render()
   */
  private static function renderEachRow(ViewExecutable $view): array {
    // $rowPlugin is nullable at runtime although its docblock omits `|null`.
    // @phpstan-ignore booleanNot.alwaysFalse
    if (!$view->rowPlugin) {
      return [];
    }
    $row_renders = [];
    foreach ($view->result as $row) {
      $row_renders[] = $view->rowPlugin->render($row);
    }
    return $row_renders;
  }

}
