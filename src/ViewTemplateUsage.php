<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template;

use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Drupal\Core\Url;

/**
 * Finds the View displays that render through a view template.
 *
 * A display has no config dependency on its template (that would delete the
 * View with the template), so usage is found by checking each display's
 * effective Format settings.
 */
class ViewTemplateUsage {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The View displays using a view template.
   *
   * @return list<array{view: string, display: string, label: string, url: string}>
   *   Each display's ids, label and Views UI edit URL (empty without Views UI).
   */
  public function displaysUsing(string $template_id): array {
    return $this->displaysByTemplate()[$template_id] ?? [];
  }

  /**
   * The View displays using each view template, in one pass over the Views.
   *
   * Not memoized: a View saved earlier in the request must count.
   *
   * @return array<string, list<array{view: string, display: string, label: string, url: string}>>
   *   Keyed by template id; templates no display uses are absent.
   */
  public function displaysByTemplate(): array {
    $found = [];
    /** @var \Drupal\views\ViewEntityInterface $view */
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      $displays = $view->get('display');
      $executable = $view->getExecutable();
      foreach ($displays as $display_id => $display) {
        // Reported through the displays that inherit it, unless it is the
        // only one.
        if ($display_id === 'default' && \count($displays) > 1) {
          continue;
        }
        if (!$executable->setDisplay($display_id)) {
          continue;
        }
        $style = $executable->display_handler->getOption('style');
        if (!\is_array($style) || ($style['type'] ?? NULL) !== ViewTemplateStyle::PLUGIN_ID) {
          continue;
        }
        $template_id = $style['options'][ViewTemplateStyle::OPTION_KEY] ?? NULL;
        if (!\is_string($template_id) || $template_id === '') {
          continue;
        }
        $found[$template_id][] = [
          'view' => (string) $view->id(),
          'display' => (string) $display_id,
          'label' => $view->label() . ': ' . ($display['display_title'] ?? $display_id),
          'url' => $this->editUrl((string) $view->id(), (string) $display_id),
        ];
      }
    }
    return \array_map(static function (array $list): array {
      \usort($list, static fn (array $a, array $b): int => \strcmp($a['label'], $b['label']));
      return $list;
    }, $found);
  }

  /**
   * The Views UI edit page of a display, or an empty string.
   */
  private function editUrl(string $view_id, string $display_id): string {
    try {
      return Url::fromRoute('entity.view.edit_display_form', ['view' => $view_id, 'display_id' => $display_id])->toString();
    }
    catch (RouteNotFoundException) {
      return '';
    }
  }

}
