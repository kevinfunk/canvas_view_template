<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\ViewExecutable;

/**
 * Keeps Views output the same from one editor preview to the next.
 *
 * Views gives every render a random id (`js-view-dom-id-…`), so each preview
 * response differs and the Canvas editor keeps reloading it. In preview
 * requests the id is derived from the View and display instead.
 */
final class ViewTemplatePreviewHooks {

  /**
   * How many times each View display has been rendered in this request.
   *
   * Two of the same display on one page need distinct, stable ids.
   *
   * @var array<string, int>
   */
  private array $rendered = [];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_views_pre_view().
   *
   * Views only fills in the id if it is empty.
   */
  #[Hook('views_pre_view')]
  public function preView(ViewExecutable $view, string $display_id, array &$args): void {
    if (!\str_starts_with((string) $this->routeMatch->getRouteName(), 'canvas.api.layout.') || !empty($view->dom_id)) {
      return;
    }
    $key = $view->storage->id() . ':' . $display_id;
    $this->rendered[$key] = ($this->rendered[$key] ?? 0) + 1;
    $view->dom_id = \hash('sha256', 'canvas_view_template:' . $key . ':' . $this->rendered[$key]);
  }

}
