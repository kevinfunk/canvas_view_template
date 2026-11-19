<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Blocks uninstall while a View display still uses a view template.
 *
 * Views depend on the module that provides their style, so uninstalling would
 * delete those Views with all their other displays.
 */
final class ViewTemplateUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  private const LISTED = 10;

  public function __construct(
    private readonly ViewTemplateUsage $usage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'canvas_view_template') {
      return [];
    }
    $labels = [];
    foreach ($this->usage->displaysByTemplate() as $displays) {
      \array_push($labels, ...\array_column($displays, 'label'));
    }
    if ($labels === []) {
      return [];
    }
    $labels = \array_values(\array_unique($labels));
    $shown = \implode(', ', \array_slice($labels, 0, self::LISTED));
    $more = \count($labels) - self::LISTED;
    if ($more > 0) {
      $shown .= ' ' . $this->t('and @count more', ['@count' => $more]);
    }
    return [
      $this->t('Uninstalling would delete the Views that use a view template. Change the Format of these displays first: @displays.', ['@displays' => $shown]),
    ];
  }

}
