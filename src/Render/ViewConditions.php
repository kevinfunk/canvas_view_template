<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\ViewExecutable;

/**
 * The conditions a "View condition" can test, and how each is decided.
 *
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateCondition
 */
final class ViewConditions {

  public const string HAS_RESULTS = 'has_results';

  public const string MULTIPLE_PAGES = 'multiple_pages';

  public const string FIRST_PAGE = 'first_page';

  public const string NOT_FIRST_PAGE = 'not_first_page';

  public const string FILTERS_APPLIED = 'filters_applied';

  public const string HAS_HEADER = 'has_header';

  public const string HAS_FOOTER = 'has_footer';

  public const string HAS_MORE = 'has_more';

  public const string HAS_ATTACHMENTS = 'has_attachments';

  public const string HAS_FEED = 'has_feed';

  /**
   * The condition used until another is chosen.
   */
  public const string DEFAULT = self::HAS_RESULTS;

  /**
   * The conditions, with their labels, in the order they are offered.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by condition.
   */
  public static function options(): array {
    return [
      self::HAS_RESULTS => new TranslatableMarkup('The view has results'),
      self::MULTIPLE_PAGES => new TranslatableMarkup('The results have more than one page'),
      self::FIRST_PAGE => new TranslatableMarkup('It is the first page'),
      self::NOT_FIRST_PAGE => new TranslatableMarkup('It is not the first page'),
      self::FILTERS_APPLIED => new TranslatableMarkup('Exposed filters are applied'),
      self::HAS_HEADER => new TranslatableMarkup('The view has a header'),
      self::HAS_FOOTER => new TranslatableMarkup('The view has a footer'),
      self::HAS_MORE => new TranslatableMarkup('The view has a "more" link'),
      self::HAS_ATTACHMENTS => new TranslatableMarkup('The view has attachments'),
      self::HAS_FEED => new TranslatableMarkup('The view has a feed'),
    ];
  }

  /**
   * Decides a condition.
   *
   * @param string $condition
   *   One of the constants above. An unknown value is treated as met, so
   *   content is never silently hidden by a setting that can't be read.
   * @param array<string, bool> $facts
   *   What ::collect() worked out.
   * @param array<string, mixed> $areas
   *   The pieces Views built, keyed `header`, `footer`, `more`,
   *   `feed_icons`, `attachment_before` and `attachment_after`.
   */
  public static function isMet(string $condition, array $facts, array $areas): bool {
    return match ($condition) {
      self::HAS_RESULTS => !empty($facts['has_results']),
      self::MULTIPLE_PAGES => !empty($facts['multiple_pages']),
      self::FIRST_PAGE => !empty($facts['first_page']),
      self::NOT_FIRST_PAGE => empty($facts['first_page']),
      self::FILTERS_APPLIED => !empty($facts['filters_applied']),
      self::HAS_HEADER => !empty($areas['header']),
      self::HAS_FOOTER => !empty($areas['footer']),
      self::HAS_MORE => !empty($areas['more']),
      self::HAS_ATTACHMENTS => !empty($areas['attachment_before']) || !empty($areas['attachment_after']),
      self::HAS_FEED => !empty($areas['feed_icons']),
      default => TRUE,
    };
  }

  /**
   * Works out what the conditions that look at the view itself need.
   *
   * @return array<string, bool>
   *   `has_results`, `multiple_pages`, `first_page` and `filters_applied`.
   */
  public static function collect(ViewExecutable $view): array {
    $current_page = (int) ($view->getCurrentPage() ?? 0);
    $per_page = (int) $view->getItemsPerPage();
    $total = (int) ($view->total_rows ?? 0);
    // Not every pager counts the total (the mini pager only knows whether
    // there is a next page), so any sign of another page counts.
    $multiple_pages = $current_page > 0
      || ($per_page > 0 && $total > $per_page)
      || (isset($view->pager) && $view->pager->hasMoreRecords());
    return [
      'has_results' => !empty($view->result),
      'multiple_pages' => $multiple_pages,
      'first_page' => $current_page === 0,
      'filters_applied' => self::filtersApplied($view),
    ];
  }

  /**
   * Checks whether a visitor has given any exposed filter a value.
   *
   * Only what was submitted counts, not a filter's default.
   */
  private static function filtersApplied(ViewExecutable $view): bool {
    $input = $view->getExposedInput();
    foreach ($view->filter ?? [] as $filter) {
      if (!$filter->isExposed()) {
        continue;
      }
      // A grouped filter is submitted under its group's identifier.
      $identifier = $filter->isAGroup()
        ? ($filter->options['group_info']['identifier'] ?? NULL)
        : ($filter->options['expose']['identifier'] ?? NULL);
      $value = \is_string($identifier) ? ($input[$identifier] ?? NULL) : NULL;
      if (\is_array($value)) {
        $value = \array_filter($value, static fn(mixed $item): bool => $item !== '' && $item !== 'All' && $item !== 0 && $item !== '0');
      }
      if ($value !== NULL && $value !== '' && $value !== 'All' && $value !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
