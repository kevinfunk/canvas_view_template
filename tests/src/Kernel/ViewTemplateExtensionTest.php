<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Extension\CanvasExtensionPluginManager;
use Drupal\canvas\Extension\CanvasExtensionTypeEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the "View templates" Canvas editor extension.
 */
#[Group('canvas_view_template')]
final class ViewTemplateExtensionTest extends ViewTemplateKernelTestBase {

  /**
   * The module's page extension is discovered and points at real files.
   */
  public function testPageExtensionIsDiscovered(): void {
    $extension = $this->container->get(CanvasExtensionPluginManager::class)->getDefinitions()['view_templates'] ?? NULL;
    self::assertNotNull($extension);

    self::assertSame(CanvasExtensionTypeEnum::Page, $extension->getType());
    self::assertSame(['administer view template'], $extension->getPermissions());
    $module_path = \dirname(__DIR__, 3);
    self::assertStringEndsWith('/canvas_view_template/extension/index.html', $extension->getUrl());
    self::assertStringEndsWith('/canvas_view_template/extension/icon.svg', $extension->getIcon());
    foreach (['index.html', 'icon.svg', 'app.js', 'app.css', 'dialog.css'] as $file) {
      self::assertFileExists($module_path . '/extension/' . $file);
    }
  }

}
