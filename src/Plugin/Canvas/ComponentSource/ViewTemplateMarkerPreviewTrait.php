<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Builds the editor preview markup shared by this module's components.
 *
 * The using class needs `StringTranslationTrait` (provided by `PluginBase`).
 */
trait ViewTemplateMarkerPreviewTrait {

  /**
   * Builds a preview placeholder in Canvas's marker style.
   *
   * @param string $kind
   *   The placeholder kind: picks the icon and `cvt-marker--KIND` class.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The component's name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $caption
   *   A short description shown under the title.
   * @param array<int, \Drupal\Core\StringTranslation\TranslatableMarkup|string> $chips
   *   Setting summaries shown as pills in the header.
   *
   * @return array
   *   A render array that callers may extend.
   */
  protected function buildMarkerStylePreview(string $kind, TranslatableMarkup $title, TranslatableMarkup|string $caption, array $chips = []): array {
    $chip_items = [];
    foreach ($chips as $index => $chip) {
      $chip_items["chip_$index"] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $chip,
        '#attributes' => ['class' => ['cvt-marker__chip']],
      ];
    }
    $header = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cvt-marker__header']],
      'icon' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => Markup::create($this->markerIcon($kind)),
        '#attributes' => ['class' => ['cvt-marker__icon']],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['cvt-marker__text']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $title,
          '#attributes' => ['class' => ['canvas--page-content-marker-placeholder__title']],
        ],
        'caption' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $caption,
          '#attributes' => ['class' => ['canvas--page-content-marker-placeholder__caption']],
        ],
      ],
    ];
    if ($chip_items !== []) {
      $header['chips'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['cvt-marker__chips']],
      ] + $chip_items;
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        // marker-preview makes it full-width in a CSS grid (no `span` here).
        'class' => [
          'canvas--page-content-marker-placeholder',
          'canvas-view-template--marker-preview',
          'cvt-marker',
          "cvt-marker--$kind",
        ],
      ],
      'header' => $header,
      '#attached' => ['library' => ['canvas/preview', 'canvas_view_template/preview']],
    ];
  }

  /**
   * Returns the outline icon SVG for a placeholder kind.
   */
  private function markerIcon(string $kind): string {
    $shapes = match ($kind) {
      'rows' => '<rect x="2.5" y="2.5" width="10" height="2.5" rx="0.5"/><rect x="2.5" y="6.25" width="10" height="2.5" rx="0.5"/><rect x="2.5" y="10" width="10" height="2.5" rx="0.5"/>',
      'filters' => '<path d="M2.5 3h10L9 7.5V12l-3-1.25V7.5L2.5 3Z"/>',
      'pager' => '<path d="M4 4.5 1.75 7.5 4 10.5M11 4.5l2.25 3L11 10.5"/><circle cx="5.75" cy="7.5" r="0.5"/><circle cx="7.5" cy="7.5" r="0.5"/><circle cx="9.25" cy="7.5" r="0.5"/>',
      'item' => '<rect x="1.5" y="4" width="12" height="7" rx="1"/><path d="M4 6.75h4M4 8.5h6"/>',
      'header' => '<rect x="2.5" y="2.5" width="10" height="3" rx="0.5"/><path d="M2.5 8.5h10M2.5 11h6"/>',
      'footer' => '<path d="M2.5 4h10M2.5 6.5h6"/><rect x="2.5" y="9.5" width="10" height="3" rx="0.5"/>',
      'empty' => '<rect x="2.5" y="3.5" width="10" height="8" rx="1" stroke-dasharray="2 1.5"/>',
      'title' => '<path d="M3 3.5h9M7.5 3.5v8"/>',
      'more' => '<path d="M2.5 7.5h9M8.5 4.5l3 3-3 3"/>',
      'attachments' => '<rect x="2.5" y="2.5" width="10" height="3.5" rx="0.5"/><rect x="2.5" y="9" width="10" height="3.5" rx="0.5"/>',
      'link' => '<path d="M6.5 8.5a2.5 2.5 0 0 0 3.5 0l2-2a2.5 2.5 0 0 0-3.5-3.5l-.75.75M8.5 6.5a2.5 2.5 0 0 0-3.5 0l-2 2a2.5 2.5 0 0 0 3.5 3.5l.75-.75"/>',
      'condition' => '<path d="M2.5 3h10L9 8v4l-3-1.5V8L2.5 3Z"/>',
      'feed' => '<circle cx="4" cy="11" r="1"/><path d="M3 7a5 5 0 0 1 5 5M3 3.5A8.5 8.5 0 0 1 11.5 12"/>',
      'summary' => '<path d="M5.5 2.5 4.5 12.5M10.5 2.5 9.5 12.5M3 5.5h9.5M2.5 9.5H12"/>',
      'field' => '<path d="M2.5 3.5h5.5l4.5 4.5-4.5 4.5L2.5 8V3.5Z"/><circle cx="5.25" cy="6" r="0.6"/>',
      default => '<path d="M12.25 7.5a4.75 4.75 0 1 1-1.5-3.45"/><path d="M11.75 1.75v2.5h-2.5"/>',
    };
    return '<svg width="16" height="16" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $shapes . '</svg>';
  }

}
