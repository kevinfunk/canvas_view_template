<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\Core\Config\ConfigFactory;

/**
 * A config factory that counts how many Component configs are asked for.
 */
final class CountingConfigFactory extends ConfigFactory {

  /**
   * How many Component config names have been requested.
   */
  public static int $componentReads = 0;

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $names): array {
    foreach ($names as $name) {
      if (\str_starts_with($name, 'canvas.component.')) {
        self::$componentReads++;
      }
    }
    return parent::loadMultiple($names);
  }

}
