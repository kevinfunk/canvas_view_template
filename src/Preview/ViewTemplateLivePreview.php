<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Preview;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Renders a view template with the View its editor chose to preview it with.
 *
 * Shared by the editor's preview response and the route that re-renders it
 * when a filter or pager is used.
 */
final class ViewTemplateLivePreview {

  public function __construct(
    private readonly ViewTemplatePreviewSelection $selection,
    private readonly ViewPreviewBuilder $builder,
    private readonly ViewTemplateRenderer $renderer,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * The template rendered with real content, or NULL if no View is chosen.
   *
   * Renders the autosaved draft, which is what the editor shows.
   */
  public function build(ViewTemplate $template): ?array {
    $choice = $this->selection->get((string) $template->id());
    if ($choice === NULL) {
      return NULL;
    }
    $autosave = $this->autoSaveManager->getAutoSaveEntity($template);
    $draft = !$autosave->isEmpty() && $autosave->entity instanceof ViewTemplate ? $autosave->entity : $template;
    $uses_summary = \in_array(ViewTemplateRenderer::VIEW_SUMMARY_COMPONENT_ID, \array_column($draft->getComponentTree()->getValue(), 'component_id'), TRUE);
    $pieces = $this->builder->build($choice['view'], $choice['display'], $uses_summary);
    if ($pieces === NULL) {
      return NULL;
    }
    $build = $this->renderer->render(
      $draft,
      $pieces['rows'],
      $pieces['exposed'],
      $pieces['pager'],
      $pieces['row_renders'],
      $pieces['current_page'],
      $pieces['areas'],
      $pieces['field_data'],
      $pieces['summary'],
      $pieces['facts'],
      $pieces['item_urls'],
    );
    // Private, per user: both the choice and the draft are.
    CacheableMetadata::createFromRenderArray($build)
      ->merge($pieces['cacheability'])
      ->addCacheContexts(['user', 'url.query_args'])
      ->applyTo($build);
    return $build;
  }

}
