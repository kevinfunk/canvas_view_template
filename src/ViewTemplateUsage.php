<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template;

use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Finds the View displays that render through a view template.
 *
 * A display has no config dependency on its template (that would delete the
 * View with the template), so usage is found by reading each display's
 * effective Format settings from the Views' config. No View is built: this
 * runs for the panel, the delete check and several forms.
 */
class ViewTemplateUsage {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
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
   * Not memoized: a View saved earlier in the request must count. Disabled
   * Views count too. A display that does not override the Format uses the
   * default display's. The default display is listed only when no other
   * display inherits its Format, so a template is never hidden from the delete
   * lock and inheriting displays are not listed twice.
   *
   * @return array<string, list<array{view: string, display: string, label: string, url: string}>>
   *   Keyed by template id; templates no display uses are absent.
   */
  public function displaysByTemplate(): array {
    $found = [];
    foreach ($this->configFactory->loadMultiple($this->configFactory->listAll('views.view.')) as $config) {
      $displays = $config->get('display');
      if (!\is_array($displays)) {
        continue;
      }
      $view_id = (string) $config->get('id');
      $default_template = $this->templateId($displays['default']['display_options']['style'] ?? NULL);
      $inherited = FALSE;
      foreach ($displays as $display_id => $display) {
        if ($display_id === 'default') {
          continue;
        }
        $options = $display['display_options'] ?? [];
        // Without a `defaults` flag, Views inherits unless the display has its
        // own style.
        $inherits = (bool) ($options['defaults']['style'] ?? !isset($options['style']));
        $template_id = $inherits ? $default_template : $this->templateId($options['style'] ?? NULL);
        $inherited = $inherited || ($inherits && $default_template !== NULL);
        if ($template_id !== NULL) {
          $found[$template_id][] = $this->entry($view_id, (string) $config->get('label'), (string) $display_id, $display);
        }
      }
      if ($default_template !== NULL && !$inherited) {
        $found[$default_template][] = $this->entry($view_id, (string) $config->get('label'), 'default', $displays['default']);
      }
    }
    return \array_map(static function (array $list): array {
      \usort($list, static fn (array $a, array $b): int => \strcmp($a['label'], $b['label']));
      return $list;
    }, $found);
  }

  /**
   * The template a style option names, if it is this module's style.
   */
  private function templateId(mixed $style): ?string {
    if (!\is_array($style) || ($style['type'] ?? NULL) !== ViewTemplateStyle::PLUGIN_ID) {
      return NULL;
    }
    $template_id = $style['options'][ViewTemplateStyle::OPTION_KEY] ?? NULL;
    return \is_string($template_id) && $template_id !== '' ? $template_id : NULL;
  }

  /**
   * One display's entry in the usage list.
   *
   * @return array{view: string, display: string, label: string, url: string}
   *   The ids, label and edit URL.
   */
  private function entry(string $view_id, string $view_label, string $display_id, array $display): array {
    return [
      'view' => $view_id,
      'display' => $display_id,
      'label' => $view_label . ': ' . ($display['display_title'] ?? $display_id),
      'url' => $this->editUrl($view_id, $display_id),
    ];
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
