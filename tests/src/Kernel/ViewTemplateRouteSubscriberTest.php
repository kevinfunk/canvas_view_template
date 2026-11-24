<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Routing\ViewTemplateRouteSubscriber;
use Drupal\Core\Routing\RouteBuildEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests that Canvas's config API routes accept view templates.
 */
#[CoversClass(ViewTemplateRouteSubscriber::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateRouteSubscriberTest extends ViewTemplateKernelTestBase {

  private const array ROUTES = [
    'canvas.api.config.get',
    'canvas.api.config.patch',
    'canvas.api.config.delete',
    'canvas.api.config.post',
    'canvas.api.config.list',
  ];

  /**
   * Whether a route's entity type requirement allows a type.
   */
  private function allows(Route $route, string $type): bool {
    return (bool) \preg_match('{^(?:' . $route->getRequirement('canvas_config_entity_type_id') . ')$}', $type);
  }

  /**
   * In the real route table, view templates are reachable and others kept.
   */
  public function testRealRoutesAllowViewTemplates(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (self::ROUTES as $name) {
      $route = $provider->getRouteByName($name);
      self::assertTrue($this->allows($route, 'canvas_view_template'), $name);
      self::assertTrue($this->allows($route, 'js_component'), "$name still allows Canvas's own types.");
      self::assertFalse($this->allows($route, 'unknown_type'), "$name does not allow just anything.");
      self::assertSame(1, \substr_count($route->getRequirement('canvas_config_entity_type_id'), 'canvas_view_template'), "$name lists it once.");
    }
  }

  /**
   * The list route generates a URL for view templates.
   */
  public function testListUrlIsGenerated(): void {
    $this->installEntitySchema('path_alias');
    $url = $this->container->get('url_generator')->generateFromRoute('canvas.api.config.list', ['canvas_config_entity_type_id' => 'canvas_view_template']);
    self::assertStringEndsWith('/canvas_view_template', $url);
  }

  /**
   * The patch adds the type once, leaves other routes alone, and is idempotent.
   */
  public function testAlterRoutes(): void {
    $collection = new RouteCollection();
    $collection->add('canvas.api.config.get', new Route('/a/{canvas_config_entity_type_id}', [], ['canvas_config_entity_type_id' => '(pattern|component)']));
    $collection->add('canvas.api.config.list', new Route('/b/{canvas_config_entity_type_id}', [], ['canvas_config_entity_type_id' => '(pattern|canvas_view_template)']));
    $collection->add('canvas.api.config.patch', new Route('/c/{canvas_config_entity_type_id}'));
    $collection->add('other.route', new Route('/d/{canvas_config_entity_type_id}', [], ['canvas_config_entity_type_id' => '(pattern)']));
    $subscriber = new ViewTemplateRouteSubscriber();

    for ($run = 0; $run < 2; $run++) {
      $subscriber->onAlterRoutes(new RouteBuildEvent($collection));
      self::assertSame('(pattern|component|canvas_view_template)', $collection->get('canvas.api.config.get')->getRequirement('canvas_config_entity_type_id'), "run $run");
      self::assertSame('(pattern|canvas_view_template)', $collection->get('canvas.api.config.list')->getRequirement('canvas_config_entity_type_id'), 'Already present: unchanged.');
      self::assertNull($collection->get('canvas.api.config.patch')->getRequirement('canvas_config_entity_type_id'), 'No requirement: untouched.');
      self::assertSame('(pattern)', $collection->get('other.route')->getRequirement('canvas_config_entity_type_id'), 'Other routes: untouched.');
      self::assertNull($collection->get('canvas.api.config.delete'));
    }
  }

  /**
   * A longer id that merely contains ours does not count as ours.
   */
  public function testSimilarIdIsNotMistakenForOurs(): void {
    $collection = new RouteCollection();
    $collection->add('canvas.api.config.get', new Route('/a/{canvas_config_entity_type_id}', [], ['canvas_config_entity_type_id' => '(pattern|content_canvas_view_template)']));

    (new ViewTemplateRouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));

    self::assertSame('(pattern|content_canvas_view_template|canvas_view_template)', $collection->get('canvas.api.config.get')->getRequirement('canvas_config_entity_type_id'));
  }

}
