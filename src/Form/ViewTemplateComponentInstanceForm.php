<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Form;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Form\ComponentInstanceForm;
use Drupal\canvas\Form\FormIdPreRender;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Edits a component instance placed in a view template.
 *
 * A fork of Canvas's ComponentInstanceForm::buildForm(), which cannot be
 * reused: the class is final and its assert() only accepts Canvas's own
 * entity types. KEEP IN SYNC with it (last compared against Canvas 1.13.0).
 *
 * The form ID is deliberately Canvas's own, so its alters and AJAX handling
 * apply. The route
 * (`canvas.api.form.component_instance.canvas_view_template`) must keep the
 * `canvas.api.form.` prefix, which selects the canvas_stark theme.
 *
 * @see \Drupal\canvas\Form\ComponentInstanceForm
 * @see \Drupal\canvas\Theme\CanvasThemeNegotiator::applies()
 */
final class ViewTemplateComponentInstanceForm extends FormBase {

  public function __construct(
    // Protected so DependencySerializationTrait can access them.
    protected ComponentTreeLoader $componentTreeLoader,
    protected ThemeHandlerInterface $themeHandler,
    protected ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentTreeLoader::class),
      $container->get(ThemeHandlerInterface::class),
      $container->get(ComponentSourceManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return ComponentInstanceForm::FORM_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ViewTemplate $entity = NULL): array {
    if ($entity === NULL) {
      throw new \UnexpectedValueException('The $entity parameter should never be NULL.');
    }
    // @phpstan-ignore-next-line property.notFound
    if (!$this->themeHandler->themeExists('canvas_stark') || !$this->themeHandler->listInfo()['canvas_stark']->status) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The canvas_stark theme must be enabled for this form to work.'),
      ];
    }
    // An empty stand-in host, so entity-bound props still convert.
    $host_entity = $entity->createEmptyTargetEntity();

    $request = $this->getRequest();
    $tree = $request->request->getString('form_canvas_tree');
    $decoded_tree = \json_decode($tree, TRUE);
    $type = \is_array($decoded_tree) && \is_string($decoded_tree['type'] ?? NULL) ? $decoded_tree['type'] : '';
    [$component_id, $version] = \array_pad(\explode('@', $type, 2), 2, '');
    if ($version === '') {
      throw new BadRequestHttpException('No component version specified.');
    }
    $component = Component::load($component_id);
    if (!$component instanceof ComponentInterface) {
      throw new BadRequestHttpException('Unknown component.');
    }
    // Older instances must keep their own version's settings.
    $component->loadVersion($version);
    if ($request->query->has('form_canvas_selected')) {
      $component_instance_uuid = $request->query->getString('form_canvas_selected');
    }
    else {
      $component_instance_uuid = $request->request->getString('form_canvas_selected');
    }

    $props = $request->request->getString('form_canvas_props');
    $client_model = \json_decode($props, TRUE);

    // Resent on later AJAX requests. The `form_` prefix keeps them out of
    // the UI state.
    $form['form_canvas_selected'] = [
      '#type' => 'hidden',
      '#value' => $component_instance_uuid,
    ];
    $form['form_canvas_tree'] = [
      '#type' => 'hidden',
      '#value' => $tree,
    ];
    $form['form_canvas_props'] = [
      '#type' => 'hidden',
      '#value' => $props,
    ];

    // Changes are saved via Redux, not a traditional submit.
    $form['#method'] = 'dialog';

    $parents = ['canvas_component_props', $component_instance_uuid];
    $sub_form = ['#parents' => $parents, '#tree' => TRUE];
    if (!$component->getComponentSource()->isBroken()) {
      $inputs = $component->getComponentSource()->clientModelToInput($component_instance_uuid, $component, $client_model, $host_entity);
      $instance_form = $component->getComponentSource()->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs, $entity, $component->get('settings'));
    }
    else {
      // Broken component: show the current values so they can be copied.
      $inputs_to_show = match (TRUE) {
        $component->getComponentSource() instanceof JsonSchemaPropsComponentSourceBase && \is_array($client_model) && \array_key_exists('resolved', $client_model) => $client_model['resolved'],
        $client_model === \json_decode('undefined') => $this->componentTreeLoader->load($entity)
          ->getComponentTreeItemByUuid($component_instance_uuid)
          ?->getInputs() ?? [],
        default => (array) $client_model,
      };
      $fallback_source = $this->componentSourceManager->createInstance(Fallback::PLUGIN_ID, ['fallback_reason' => $this->t('Component is missing. Fix the component or copy values to a new component.')]);
      \assert($fallback_source instanceof ComponentSourceInterface);
      $instance_form = $fallback_source->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs_to_show, $entity, $component->get('settings'));
    }

    $form['#attributes']['data-form-id'] = ComponentInstanceForm::FORM_ID;

    $form['canvas_component_props'][$component_instance_uuid] = $instance_form;
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    $is_ajax = $request->request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) ?? $request->query->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER);
    if ($is_ajax !== NULL) {
      // Pre-render callbacks don't fire during AJAX rendering.
      FormIdPreRender::addAjaxAttribute($form, $form['#attributes']['data-form-id']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Nothing to do: changes are saved via Redux.
  }

}
