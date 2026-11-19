<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_view_template\Entity\ViewTemplate;

/**
 * Finds the version of a view template the editor is working on.
 */
final class ViewTemplateDrafts {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * The unpublished draft of a template, or the template if it has none.
   */
  public function latest(ViewTemplate $template): ViewTemplate {
    $autosave = $this->autoSaveManager->getAutoSaveEntity($template);
    return !$autosave->isEmpty() && $autosave->entity instanceof ViewTemplate ? $autosave->entity : $template;
  }

}
