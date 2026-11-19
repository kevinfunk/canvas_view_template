<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Routing;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds "canvas_view_template" to Canvas's config CRUD API route allowlist.
 *
 * Canvas's `canvas.api.config.{get,patch,delete,post,list}` routes (defined
 * in canvas.routing.yml) constrain their `canvas_config_entity_type_id`
 * route parameter with a literal regex enumerating the config entity types
 * eligible for the generic config CRUD API the Canvas editor UI uses for
 * create/list/etc. — it does not discover eligible types generically, so a
 * new type is unreachable through that API until it is added to the regex.
 *
 * @see \Drupal\canvas\Controller\ApiConfigControllers
 * @see \Drupal\canvas\Routing\ViewModeRouteSubscriber
 * @see \Drupal\canvas_view_template\EventSubscriber\ViewTemplateEntityFormControllerSubscriber
 */
final class ViewTemplateRouteSubscriber extends RouteSubscriberBase {

  /**
   * The route names whose `canvas_config_entity_type_id` regex to extend.
   */
  private const array CONFIG_CRUD_ROUTE_NAMES = [
    'canvas.api.config.get',
    'canvas.api.config.patch',
    'canvas.api.config.delete',
    'canvas.api.config.post',
    'canvas.api.config.list',
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::CONFIG_CRUD_ROUTE_NAMES as $route_name) {
      $route = $collection->get($route_name);
      if ($route === NULL) {
        // Canvas is not installed, or has changed its route names.
        continue;
      }
      $requirement = $route->getRequirement('canvas_config_entity_type_id');
      if ($requirement === NULL) {
        continue;
      }
      // Whole alternatives, so a longer id containing ours does not count.
      $allowed = \explode('|', \trim($requirement, '()'));
      if (\in_array(ViewTemplate::ENTITY_TYPE_ID, $allowed, TRUE)) {
        continue;
      }
      $route->setRequirement(
        'canvas_config_entity_type_id',
        \rtrim($requirement, ')') . '|' . ViewTemplate::ENTITY_TYPE_ID . ')',
      );
    }
  }

}
