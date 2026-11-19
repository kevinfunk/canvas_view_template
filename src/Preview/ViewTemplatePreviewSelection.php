<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Preview;

use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\views\Entity\View;

/**
 * Remembers which View each user previews a view template with.
 *
 * Per-user editor state, not part of the template (so never exported), kept
 * in the private temp store. The value is `VIEW_ID/DISPLAY_ID`, or NONE for
 * "show the placeholders".
 */
final class ViewTemplatePreviewSelection {

  /**
   * The value meaning "no View: keep showing the placeholders".
   *
   * Not `_none`: Canvas turns that into NULL, which it also sends when the
   * field isn't shown, so it must not be taken for a choice.
   */
  public const string NONE = 'none';

  private const string COLLECTION = 'canvas_view_template';

  /**
   * The display plugins that render a listing, so can be previewed with.
   */
  public const array PREVIEWABLE_DISPLAY_PLUGINS = ['default', 'page', 'block', 'embed'];

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly ViewTemplateUsage $usage,
  ) {}

  /**
   * The current user's selection for a template, as `VIEW_ID/DISPLAY_ID`.
   *
   * Until the user chooses, or if the chosen display is gone or disabled,
   * the first View display using the template, or NONE if none does.
   */
  public function getValue(string $template_id): string {
    $value = NULL;
    try {
      $value = $this->tempStoreFactory->get(self::COLLECTION)->get("preview:$template_id");
    }
    catch (\Exception) {
      // No session (e.g. CLI): nothing is remembered.
    }
    if ($value === self::NONE) {
      return self::NONE;
    }
    $choice = \is_string($value) ? self::parse($value) : NULL;
    if ($choice !== NULL && self::exists($choice['view'], $choice['display'])) {
      return $value;
    }
    foreach ($this->usage->displaysUsing($template_id) as $default) {
      if (self::exists($default['view'], $default['display'])) {
        return $default['view'] . '/' . $default['display'];
      }
    }
    return self::NONE;
  }

  /**
   * Checks that an enabled View has an enabled display to preview with.
   */
  public static function exists(string $view_id, string $display_id): bool {
    $view = View::load($view_id);
    if (!$view instanceof View || !$view->status()) {
      return FALSE;
    }
    $display = $view->get('display')[$display_id] ?? NULL;
    return \is_array($display)
      && \in_array($display['display_plugin'] ?? NULL, self::PREVIEWABLE_DISPLAY_PLUGINS, TRUE)
      && ($display['display_options']['enabled'] ?? TRUE) !== FALSE;
  }

  /**
   * The current user's selection, parsed, or NULL for none.
   *
   * @return array{view: string, display: string}|null
   *   The View and display ids, or NULL.
   */
  public function get(string $template_id): ?array {
    return self::parse($this->getValue($template_id));
  }

  /**
   * Remembers the user's choice: a View display, or NONE.
   *
   * NULL changes nothing; any other invalid value resets to the default.
   */
  public function set(string $template_id, mixed $value): void {
    if ($value === NULL) {
      return;
    }
    try {
      $store = $this->tempStoreFactory->get(self::COLLECTION);
      if (\is_string($value) && self::parse($value) !== NULL) {
        $store->set("preview:$template_id", $value);
      }
      elseif ($value === self::NONE) {
        $store->set("preview:$template_id", self::NONE);
      }
      else {
        $store->delete("preview:$template_id");
      }
    }
    catch (\Exception) {
      // No session: nothing to remember.
    }
  }

  /**
   * Splits `VIEW_ID/DISPLAY_ID`; NULL for NONE or anything malformed.
   *
   * @return array{view: string, display: string}|null
   *   The View and display ids, or NULL.
   */
  public static function parse(string $value): ?array {
    if (!\preg_match('/^([a-z0-9_]+)\/([a-z0-9_]+)$/', $value, $matches)) {
      return NULL;
    }
    return ['view' => $matches[1], 'display' => $matches[2]];
  }

}
