<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\EventSubscriber;

use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Form\ViewTemplatePreviewForm;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateNoNestedRepeaterConstraintValidator;
use Drupal\canvas_view_template\Plugin\Validation\Constraint\ViewTemplateRepeaterContentsConstraintValidator;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\ViewTemplateDrafts;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shows a View's real content in the full-width preview of a view template.
 *
 * Canvas renders the editor frame and the full-width preview from the same
 * HTML, so both versions are sent: the editor's placeholders and the live
 * render. A script shows the live one only outside the editor's own frames
 * (marked `data-canvas-iframe`).
 *
 * @see \Drupal\canvas_view_template\Form\ViewTemplatePreviewForm
 */
final class ViewTemplatePreviewSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ViewTemplatePreviewSelection $selection,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly ViewTemplateDrafts $drafts,
  ) {}

  /**
   * Remembers the View chosen in "Page data", which rides on each preview POST.
   */
  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $entity = $request->attributes->get('entity');
    if (!$entity instanceof ViewTemplate || $request->getMethod() !== 'POST') {
      return;
    }
    $body = \json_decode($request->getContent(), TRUE);
    $fields = \is_array($body) ? ($body['entity_form_fields'] ?? NULL) : NULL;
    if (\is_array($fields) && \array_key_exists(ViewTemplatePreviewForm::FIELD, $fields)) {
      $this->selection->set((string) $entity->id(), $fields[ViewTemplatePreviewForm::FIELD]);
    }
  }

  /**
   * Adds the live version next to the editor's version.
   */
  public function onView(ViewEvent $event): void {
    $result = $event->getControllerResult();
    $entity = $event->getRequest()->attributes->get('entity');
    if (!$result instanceof PreviewEnvelope || !$entity instanceof ViewTemplate) {
      return;
    }
    $id = (string) $entity->id();
    $data = $result->additionalData;
    if (isset($data['entity_form_fields']) && \is_array($data['entity_form_fields'])) {
      $data['entity_form_fields'][ViewTemplatePreviewForm::FIELD] = $this->selection->getValue($id);
    }
    $render = $result->previewRenderArray;

    // The live content is fetched by the full-width preview when it opens, so
    // an edit does not run the View for frames that never show it.
    $chosen = $this->selection->get($id) !== NULL;
    $hint = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['cvt-preview-hint'], 'hidden' => TRUE],
      '#value' => $chosen
        ? $this->t('Showing placeholders. The view chosen under Page data, Preview with view cannot be shown: you may not have access to it, it may need a contextual filter, or it is disabled.')
        : $this->t('Showing placeholders. To see real content, choose a view under Page data, Preview with view.'),
    ];
    if ($chosen) {
      $render = [
        'edit' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['cvt-edit-preview']],
          'content' => $render,
        ],
        'hint' => $hint,
        'live' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['cvt-live-preview'],
            'hidden' => TRUE,
            'data-cvt-lazy' => '',
            'data-cvt-url' => $this->urlGenerator->generateFromRoute('canvas_view_template.live_preview', ['view_template' => $id]),
          ],
        ],
        '#attached' => ['library' => ['canvas_view_template/live_preview']],
      ];
    }
    else {
      $render = [
        'hint' => $hint,
        'content' => $render,
        '#attached' => ['library' => ['canvas_view_template/live_preview']],
      ];
    }
    // Canvas only blocks saving these, so warn while editing.
    $warnings = $this->repeaterWarnings($entity);
    if ($warnings !== []) {
      $render = [
        'warning' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['cvt-preview-warning'], 'role' => 'status'],
          '#value' => \implode(' ', $warnings),
        ],
        'content' => $render,
        '#attached' => ['library' => ['canvas_view_template/live_preview']],
      ];
    }
    if (isset($result->previewRenderArray['#canvas_hide_page_chrome'])) {
      $render['#canvas_hide_page_chrome'] = $result->previewRenderArray['#canvas_hide_page_chrome'];
    }
    // Per user, and the choice lives in the private temp store, which
    // invalidates no cache tag when it changes.
    CacheableMetadata::createFromRenderArray($render)
      ->addCacheContexts(['user'])
      ->setCacheMaxAge(0)
      ->applyTo($render);
    if ($render !== $result->previewRenderArray || $data !== $result->additionalData) {
      $event->setControllerResult(new PreviewEnvelope($render, $data, $result->statusCode));
    }
  }

  /**
   * What is wrong inside repeaters of the template being edited (its draft).
   *
   * @return list<\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   One message per problem, empty if there are none.
   */
  private function repeaterWarnings(ViewTemplate $template): array {
    $items = $this->drafts->latest($template)->getComponentTree()->getValue();
    $warnings = [];
    if (ViewTemplateNoNestedRepeaterConstraintValidator::nestedRepeaterUuids($items) !== []) {
      $warnings[] = $this->t('A "View repeater" cannot be placed inside another "View repeater". Move it beside the first one, or the template cannot be saved.');
    }
    $labels = ViewTemplateRepeaterContentsConstraintValidator::labels();
    foreach (\array_unique(ViewTemplateRepeaterContentsConstraintValidator::misplacedComponents($items)) as $component_id) {
      $warnings[] = $this->t('A "@component" cannot be placed inside a "View repeater": it shows the whole view, not one item. Move it beside the repeater, or the template cannot be saved.', ['@component' => $labels[$component_id]]);
    }
    return $warnings;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::CONTROLLER => 'onController',
      // After the fields subscriber (10), before Canvas's own (0).
      KernelEvents::VIEW => ['onView', 5],
    ];
  }

}
