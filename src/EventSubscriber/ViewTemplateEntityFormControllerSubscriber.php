<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\EventSubscriber;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Form\ViewTemplatePreviewForm;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns an empty "Page data" form for config entities.
 *
 * `canvas.api.form.content_entity` type-hints FieldableEntityInterface, so
 * requesting it for a ViewTemplate would 500. The editor always requests it
 * and can't be patched, so this swaps in a harmless empty render array.
 *
 * @see \Drupal\canvas\Controller\EntityFormController::form()
 */
final class ViewTemplateEntityFormControllerSubscriber implements EventSubscriberInterface {

  /**
   * The route whose controller to swap for non-fieldable entities.
   */
  private const string CONTENT_ENTITY_FORM_ROUTE_NAME = 'canvas.api.form.content_entity';

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  /**
   * Swaps the controller when the resolved entity isn't fieldable.
   */
  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== self::CONTENT_ENTITY_FORM_ROUTE_NAME) {
      return;
    }
    $entity = $request->attributes->get('entity');
    if ($entity instanceof FieldableEntityInterface) {
      return;
    }
    // A view template gets its own Page data form: which View to preview with.
    if ($entity instanceof ViewTemplate) {
      $event->setController(fn (): array => $this->formBuilder->getForm(ViewTemplatePreviewForm::class, $entity));
      return;
    }
    // Not a literal `[]`: Renderer::renderRoot() short-circuits an empty array
    // without populating `#attached`/`#printed`, which Canvas requires.
    $event->setController(static fn (): array => ['#markup' => '']);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::CONTROLLER => 'onController',
    ];
  }

}
