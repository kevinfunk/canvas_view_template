<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Hook;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Render\ViewConditions;
use Drupal\canvas_view_template\Render\ViewFieldRenders;
use Drupal\canvas_view_template\Render\ViewResultSummary;
use Drupal\canvas_view_template\Render\ViewItemUrls;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\ViewExecutable;

/**
 * Renders a Views display through a Canvas view template when one is set.
 *
 * Hooks into the whole-view `views_view` theme hook rather than the style
 * plugin, which only controls `#rows`.
 */
final class ViewTemplateThemeHooks {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ViewTemplateRenderer $renderer,
  ) {}

  /**
   * Implements hook_theme_registry_alter().
   *
   * Registry::processExtension() only scans a module's templates/ for hooks
   * it declares itself, and views_view belongs to Views. Without this the
   * suggestion falls back to the base template and the output never renders.
   */
  #[Hook('theme_registry_alter')]
  public function themeRegistryAlter(array &$theme_registry): void {
    if (!isset($theme_registry['views_view__canvas_view_template'])) {
      return;
    }
    $theme_registry['views_view__canvas_view_template']['template'] = 'views-view--canvas-view-template';
    $theme_registry['views_view__canvas_view_template']['path'] = $this->moduleHandler->getModule('canvas_view_template')->getPath() . '/templates';
  }

  /**
   * Implements hook_theme_suggestions_views_view_alter().
   */
  #[Hook('theme_suggestions_views_view_alter')]
  public function themeSuggestionsViewsViewAlter(array &$suggestions, array $variables): void {
    if ($this->getSelectedViewTemplate($variables['view']) !== NULL) {
      $suggestions[] = 'views_view__canvas_view_template';
    }
  }

  /**
   * Implements hook_views_pre_build().
   *
   * Like the Views "Result summary" area, which does this in query(): without
   * it a mini pager never counts the total. Too late once rendering starts.
   *
   * @see \Drupal\views\Plugin\views\area\Result::query()
   */
  #[Hook('views_pre_build')]
  public function viewsPreBuild(ViewExecutable $view): void {
    $view_template = $this->getSelectedViewTemplate($view);
    if ($view_template !== NULL && $this->treeUses($view_template, ViewTemplateRenderer::VIEW_SUMMARY_COMPONENT_ID)) {
      $view->get_total_rows = TRUE;
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for views_view.
   *
   * Also runs when the template is missing or disabled and Views renders on
   * its own, so restoring the template refreshes cached output.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $cacheability = $this->templateCacheability($variables['view']);
    if ($cacheability !== NULL) {
      CacheableMetadata::createFromRenderArray($variables)
        ->merge($cacheability)
        ->applyTo($variables);
    }
  }

  /**
   * Implements hook_preprocess_HOOK__SUGGESTION() for views_view__canvas_view_template.
   */
  #[Hook('preprocess_views_view__canvas_view_template')]
  public function preprocessViewsViewCanvasViewTemplate(array &$variables): void {
    $view_template = $this->getSelectedViewTemplate($variables['view']);
    if ($view_template === NULL) {
      // The template was deleted or disabled since the suggestions pass.
      return;
    }
    // Per-row rendering duplicates Views' own work; only do it when needed.
    $row_renders = $this->treeNeedsIndividualRowRenders($view_template)
      ? $this->renderEachRow($variables['view'])
      : [];
    $areas = [];
    foreach (['header', 'footer', 'empty', 'more', 'feed_icons', 'attachment_before', 'attachment_after'] as $key) {
      if (!empty($variables[$key])) {
        $areas[$key] = $variables[$key];
      }
    }
    // $variables['title'] is always '' here; getTitle() includes
    // contextual-filter overrides.
    $title = $variables['view']->getTitle();
    if (\is_string($title) && $title !== '') {
      $areas['title'] = ['#plain_text' => $title];
    }
    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    $variables['canvas_view_template_output'] = $this->renderer->render(
      $view_template,
      $variables['rows'] ?? [],
      $variables['exposed'] ?? [],
      $variables['pager'] ?? [],
      $row_renders,
      $variables['view']->getCurrentPage() ?? 0,
      $areas,
      $this->treeUses($view_template, ViewTemplateRenderer::VIEW_FIELD_COMPONENT_ID) ? ViewFieldRenders::collect($variables['view']) : [],
      $this->treeUses($view_template, ViewTemplateRenderer::VIEW_SUMMARY_COMPONENT_ID) ? ViewResultSummary::collect($variables['view']) : [],
      $this->treeUses($view_template, ViewTemplateRenderer::VIEW_CONDITION_COMPONENT_ID) ? ViewConditions::collect($variables['view']) : [],
      $this->treeUses($view_template, ViewTemplateRenderer::VIEW_ITEM_LINK_COMPONENT_ID) ? ViewItemUrls::collect($variables['view'], $cacheability) : [],
    );
    $cacheability->applyTo($variables);
  }

  /**
   * Checks whether a view template's tree needs each row rendered.
   */
  private function treeNeedsIndividualRowRenders(ViewTemplate $view_template): bool {
    $view_item_component_id = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID;
    $repeater_component_ids = [
      $view_item_component_id,
      ViewTemplateRenderer::VIEW_REPEATER_COMPONENT_ID,
    ];
    foreach ($view_template->getComponentTree()->getValue() as $item) {
      if (\in_array($item['component_id'] ?? NULL, $repeater_component_ids, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks whether a view template places a component.
   */
  private function treeUses(ViewTemplate $view_template, string $component_id): bool {
    foreach ($view_template->getComponentTree()->getValue() as $item) {
      if (($item['component_id'] ?? NULL) === $component_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the enabled view template selected for a View, if any.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view being rendered.
   *
   * @return \Drupal\canvas_view_template\Entity\ViewTemplate|null
   *   The view template, or NULL if there is none.
   */
  private function getSelectedViewTemplate(ViewExecutable $view): ?ViewTemplate {
    $view_template = $this->loadSelectedViewTemplate($view);
    return $view_template instanceof ViewTemplate && $view_template->status() ? $view_template : NULL;
  }

  /**
   * What a View's output depends on about its selected template.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata|null
   *   The template's cacheability (the list's, if it is missing), or NULL if
   *   the View selects none.
   */
  private function templateCacheability(ViewExecutable $view): ?CacheableMetadata {
    if ($this->selectedViewTemplateId($view) === NULL) {
      return NULL;
    }
    $view_template = $this->loadSelectedViewTemplate($view);
    return $view_template !== NULL
      ? CacheableMetadata::createFromObject($view_template)
      : (new CacheableMetadata())->addCacheTags($this->entityTypeManager->getDefinition(ViewTemplate::ENTITY_TYPE_ID)->getListCacheTags());
  }

  /**
   * Loads the view template a View selects, enabled or not.
   */
  private function loadSelectedViewTemplate(ViewExecutable $view): ?ViewTemplate {
    $view_template_id = $this->selectedViewTemplateId($view);
    if ($view_template_id === NULL) {
      return NULL;
    }
    $view_template = $this->entityTypeManager->getStorage(ViewTemplate::ENTITY_TYPE_ID)->load($view_template_id);
    return $view_template instanceof ViewTemplate ? $view_template : NULL;
  }

  /**
   * The id of the view template a View selects, if any.
   *
   * Reads the display's option when the style plugin is not built yet (as in
   * hook_views_pre_build()).
   */
  private function selectedViewTemplateId(ViewExecutable $view): ?string {
    $style = $view->style_plugin;
    if ($style instanceof ViewTemplateStyle) {
      $options = $style->options;
    }
    elseif ($style === NULL && isset($view->display_handler)) {
      $style_option = $view->display_handler->getOption('style');
      $options = \is_array($style_option) && ($style_option['type'] ?? NULL) === ViewTemplateStyle::PLUGIN_ID ? ($style_option['options'] ?? []) : [];
    }
    else {
      return NULL;
    }
    $view_template_id = $options[ViewTemplateStyle::OPTION_KEY] ?? NULL;
    return \is_string($view_template_id) && $view_template_id !== '' ? $view_template_id : NULL;
  }

  /**
   * Renders each of a View's result rows individually.
   *
   * `#rows` combines all rows; repeaters need each one separately.
   *
   * @return array<int, array>
   *   Each result row's render array, in display order.
   *
   * @see \Drupal\views\Plugin\views\row\RowPluginBase::render()
   */
  private function renderEachRow(ViewExecutable $view): array {
    // $rowPlugin is nullable at runtime although its docblock omits `|null`.
    // @phpstan-ignore booleanNot.alwaysFalse
    if (!$view->rowPlugin) {
      return [];
    }
    $row_renders = [];
    foreach ($view->result as $row) {
      $row_renders[] = $view->rowPlugin->render($row);
    }
    return $row_renders;
  }

}
