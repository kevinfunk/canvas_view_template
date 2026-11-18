<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\EventSubscriber;

use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the `entity_form_fields`/`isPublished` keys Canvas omits.
 *
 * ApiLayoutController::get() only populates them for PageVariant and content
 * entities. Without a non-empty `entity_form_fields` the editor's "Page data"
 * spinner never resolves, so this reports the template's id/label/description.
 *
 * @see \Drupal\canvas\Controller\ApiLayoutController::get()
 */
final class ViewTemplateEntityFormFieldsSubscriber implements EventSubscriberInterface {

  /**
   * Adds the missing `entity_form_fields`/`isPublished` keys.
   *
   * Runs before Canvas's PreviewEnvelopeViewSubscriber (priority 0), which
   * reads the controller result fresh.
   */
  public function onView(ViewEvent $event): void {
    $result = $event->getControllerResult();
    if (!$result instanceof PreviewEnvelope) {
      return;
    }
    $entity = $event->getRequest()->attributes->get('entity');
    if (!$entity instanceof ViewTemplate || \array_key_exists('entity_form_fields', $result->additionalData)) {
      return;
    }
    $event->setControllerResult(new PreviewEnvelope(
      $result->previewRenderArray,
      $result->additionalData + [
        'isPublished' => $entity->status(),
        'entity_form_fields' => [
          'id' => $entity->id(),
          'label' => $entity->label(),
          'description' => $entity->get('description'),
        ],
      ],
      $result->statusCode,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::VIEW][] = ['onView', 10];
    return $events;
  }

}
