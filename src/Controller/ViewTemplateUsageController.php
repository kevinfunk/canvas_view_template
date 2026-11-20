<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Controller;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Reports which View displays use a view template.
 *
 * Used by the View templates panel, on each row and before deleting one.
 */
final class ViewTemplateUsageController extends ControllerBase {

  public function __construct(
    private readonly ViewTemplateUsage $usage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ViewTemplateUsage::class));
  }

  /**
   * Returns the displays using each view template, keyed by template id.
   */
  public function all(): JsonResponse {
    $by_template = $this->usage->displaysByTemplate();
    $templates = [];
    foreach (\array_keys($this->entityTypeManager()->getStorage(ViewTemplate::ENTITY_TYPE_ID)->loadMultiple()) as $id) {
      $templates[$id] = $this->usage->visibleTo($by_template[$id] ?? [], $this->currentUser());
    }
    $response = new JsonResponse(['templates' => $templates]);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Returns the displays using the template.
   */
  public function usage(ViewTemplate $view_template): JsonResponse {
    $response = new JsonResponse(['displays' => $this->usage->visibleTo($this->usage->displaysUsing((string) $view_template->id()), $this->currentUser())]);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
