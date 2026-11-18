<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;

/**
 * Base class for this module's kernel tests.
 *
 * Installs `canvas_dev_mode` because Canvas's `canvas.component.*` schema
 * restricts `source` plugin IDs, and strict schema checking would otherwise
 * reject this module's install config. Dev mode lifts that restriction.
 */
abstract class ViewTemplateKernelTestBase extends CanvasKernelTestBase {

  // Re-declared because a private trait method is only callable from the class
  // that uses the trait; aliased to protected for subclasses.
  use ConstraintViolationsTestTrait {
    violationsToArray as protected;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
  ];

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_view_template']);
    $this->renderer = $this->container->get(RendererInterface::class);
  }

}
