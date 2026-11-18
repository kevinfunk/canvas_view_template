<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Form;

use Drupal\canvas\Form\FormIdPreRender;
use Drupal\canvas\Hook\ModuleHooks;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Page data" panel for a view template: which View to preview it with.
 *
 * The choice is not saved on the template: it is sent with each preview
 * request and remembered per user by ViewTemplatePreviewSubscriber. The form
 * id and pre-render callback mirror Canvas's own entity forms.
 *
 * @see \Drupal\canvas_view_template\EventSubscriber\ViewTemplatePreviewSubscriber
 * @see \Drupal\canvas\Hook\ModuleHooks::formAlter()
 */
final class ViewTemplatePreviewForm extends FormBase {

  /**
   * The select's name, which is also the key Canvas sends it back under.
   */
  public const string FIELD = 'preview_view_display';

  public function __construct(
    // Not readonly: FormBase uses DependencySerializationTrait, whose
    // __wakeup() cannot re-hydrate a readonly property on PHP < 8.4.
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ViewTemplatePreviewSelection $selection,
    protected ViewTemplateUsage $usage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
      $container->get(ViewTemplatePreviewSelection::class),
      $container->get(ViewTemplateUsage::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_view_template_preview_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ViewTemplate $entity = NULL): array {
    $selected = $entity !== NULL ? $this->selection->getValue((string) $entity->id()) : ViewTemplatePreviewSelection::NONE;
    $options = [ViewTemplatePreviewSelection::NONE => $this->t('- None (show placeholders) -')] + $this->viewOptions($entity !== NULL ? (string) $entity->id() : NULL, $selected);
    $form[self::FIELD] = [
      '#type' => 'select',
      '#title' => $this->t('Preview with view'),
      '#description' => $this->t('Shows real content from this view in the full-width preview. Editing keeps the placeholders, so you can still select and move things.'),
      '#options' => $options,
      '#default_value' => isset($options[$selected]) ? $selected : ViewTemplatePreviewSelection::NONE,
    ];

    // As in Canvas's entity forms: no buttons, and the id its UI looks for.
    $form['actions']['#access'] = FALSE;
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    $form['#attributes']['data-form-id'] = ModuleHooks::PAGE_DATA_FORM_ID;
    $request = $this->getRequest();
    $is_ajax = $request->request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) ?? $request->query->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER);
    if ($is_ajax !== NULL) {
      FormIdPreRender::addAjaxAttribute($form, ModuleHooks::PAGE_DATA_FORM_ID);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Nothing to do: the choice travels with the preview requests.
  }

  /**
   * The Views displays that can be previewed, labelled "View: Display".
   *
   * Sorted by label, omitting the default display of a View that has others,
   * and marking displays using this template. Not grouped: Canvas's select
   * sends the group's label, not the value.
   *
   * @param string|null $template_id
   *   The view template being edited.
   * @param string $selected
   *   The current choice, which stays listed even if it would be left out.
   *
   * @return array<string, string>
   *   Display labels, keyed by `VIEW_ID/DISPLAY_ID`.
   */
  private function viewOptions(?string $template_id, string $selected): array {
    $options = [];
    /** @var \Drupal\views\ViewEntityInterface $view */
    foreach ($this->entityTypeManager->getStorage('view')->loadByProperties(['status' => TRUE]) as $view) {
      $displays = [];
      foreach ($view->get('display') as $display_id => $display) {
        // Only displays that render a listing.
        if (\in_array($display['display_plugin'] ?? '', ViewTemplatePreviewSelection::PREVIEWABLE_DISPLAY_PLUGINS, TRUE)) {
          $displays[$display_id] = $display;
        }
      }
      // The default display is only worth choosing when it is all there is.
      if (\count($displays) > 1 && $selected !== $view->id() . '/default') {
        unset($displays['default']);
      }
      foreach ($displays as $display_id => $display) {
        $options[$view->id() . '/' . $display_id] = $view->label() . ': ' . ($display['display_title'] ?? $display_id);
      }
    }
    \asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    if ($template_id !== NULL) {
      foreach ($this->usage->displaysUsing($template_id) as $display) {
        $key = $display['view'] . '/' . $display['display'];
        if (isset($options[$key])) {
          $options[$key] = $this->t('@label (uses this template)', ['@label' => $options[$key]]);
        }
      }
    }
    return $options;
  }

}
