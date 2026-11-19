<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides "View no results", shown only when the View has no results.
 *
 * An empty slot falls back to the Views UI "no results" content. The renderer
 * resumes the Fiber with `[]` (results exist), the Views empty area (no
 * results, empty slot), or NULL (no results: render the slot via setSlots()).
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View no results'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateEmpty extends ViewTemplateComponentSourceBase implements ComponentSourceWithSlotsInterface {

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_empty';

  public const string LOCAL_ID = 'view_empty';

  /**
   * The slot name for the "no results" content.
   */
  public const string SLOT_NAME = 'content';

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View no results');
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    // Not translated: the version hash includes these, so it must not depend
    // on the interface language.
    return [
      self::SLOT_NAME => [
        'title' => 'Content',
        'description' => 'Shown only when the view has no results.',
        // Non-empty so the editor draws a drop target (as in the repeater).
        'examples' => [''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $this->setFlaggedSlot($build, $slots, self::SLOT_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    if (\Fiber::getCurrent() !== NULL) {
      $injected = $this->suspendFor($componentUuid);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    if ($isPreview) {
      $build = $this->buildMarkerStylePreview(
        'empty',
        $this->t('View no results'),
        $this->t('Shown only when the view has no results.'),
      );
      $build[self::SLOT_NAME] = [];
      $build['#canvas_component_uuid'] = $componentUuid;
      $build['#canvas_is_preview'] = TRUE;
      $build['#cvt_show_slot'] = TRUE;
      return $build;
    }
    return ['#cvt_show_slot' => TRUE, self::SLOT_NAME => []];
  }

}
