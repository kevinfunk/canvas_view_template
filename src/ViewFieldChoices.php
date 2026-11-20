<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\Views;

/**
 * The fields "View field" offers for a view template.
 *
 * Only fields of Views the current user may access are listed.
 *
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField
 */
final class ViewFieldChoices {

  /**
   * The fields come from the previewed View display.
   */
  public const string SOURCE_PREVIEWED = 'previewed';

  /**
   * The fields come from the displays that use the template.
   */
  public const string SOURCE_USING = 'using';

  /**
   * The fields come from every View on the site.
   */
  public const string SOURCE_ALL = 'all';

  public function __construct(
    protected readonly ViewTemplatePreviewSelection $previewSelection,
    protected readonly ViewTemplateUsage $usage,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * The fields to choose from.
   *
   * Order: previewed display, displays using this template, all Views.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity being edited, normally a view template.
   *
   * @return array{options: array<string, string>, source: string}
   *   Admin labels by field name, and where they came from: one of the
   *   SOURCE_* constants.
   */
  public function forEntity(?EntityInterface $entity): array {
    $displays = [];
    $source = self::SOURCE_ALL;
    if ($entity instanceof ViewTemplate) {
      $template_id = (string) $entity->id();
      $choice = $this->previewSelection->get($template_id);
      if ($choice !== NULL) {
        $displays = [$choice];
        $source = self::SOURCE_PREVIEWED;
      }
      else {
        foreach ($this->usage->displaysUsing($template_id) as $display) {
          $displays[] = ['view' => $display['view'], 'display' => $display['display']];
        }
        $source = $displays !== [] ? self::SOURCE_USING : self::SOURCE_ALL;
      }
    }
    $options = $source === self::SOURCE_ALL ? $this->allFields() : [];
    foreach ($displays as $choice) {
      $view = Views::getView($choice['view']);
      if ($view === NULL || !$view->setDisplay($choice['display']) || !$view->access($choice['display'])) {
        continue;
      }
      foreach ($view->display_handler->getHandlers('field') as $id => $handler) {
        $options[(string) $id] ??= (string) $handler->adminLabel();
      }
    }
    \asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return ['options' => $options, 'source' => $source];
  }

  /**
   * The fields of every enabled, accessible View's default display.
   *
   * Reads raw config: building every View's handlers would be slow on big
   * sites.
   *
   * @return array<string, string>
   *   Admin labels by field name; the first View naming a field wins.
   */
  private function allFields(): array {
    $options = [];
    foreach ($this->configFactory->loadMultiple($this->configFactory->listAll('views.view.')) as $config) {
      if (!$config->get('status') || !$this->usage->canSee((string) $config->get('id'), NULL, $this->currentUser)) {
        continue;
      }
      foreach ($config->get('display.default.display_options.fields') ?? [] as $id => $field) {
        $options[(string) $id] ??= (string) (($field['admin_label'] ?? '') ?: ($field['label'] ?? '') ?: $id);
      }
    }
    return $options;
  }

}
