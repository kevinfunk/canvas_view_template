<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Tests who may use each route this module adds.
 */
#[CoversClass(ViewTemplate::class)]
#[Group('canvas_view_template')]
final class ViewTemplateRouteAccessTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
    'views',
  ];

  /**
   * The access manager.
   */
  private AccessManagerInterface $accessManager;

  /**
   * A user with no module permissions.
   */
  private AccountInterface $plainUser;

  /**
   * A user who may administer view templates.
   */
  private AccountInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    // Not uid 1, which would have every permission.
    $this->setUpCurrentUser(['uid' => 2], ['access content']);
    $this->plainUser = $this->container->get('current_user')->getAccount();
    $this->admin = $this->createUser([ViewTemplate::ADMIN_PERMISSION]);
    $this->accessManager = $this->container->get('access_manager');
    ViewTemplate::create(['id' => 'sample', 'label' => 'Sample'])->save();
  }

  /**
   * The routes that need `administer view templates`, by name and parameters.
   *
   * @return array<string, array{0: string, 1: array<string, string>}>
   *   The route name and its parameters.
   */
  public static function adminRoutes(): array {
    return [
      'usage of one template' => ['canvas_view_template.usage', ['view_template' => 'sample']],
      'usage of all templates' => ['canvas_view_template.usage_all', []],
      'duplicate' => ['canvas_view_template.duplicate', ['view_template' => 'sample']],
    ];
  }

  /**
   * The routes that follow the template's own edit access.
   *
   * @return array<string, array{0: string, 1: array<string, string>}>
   *   The route name and its parameters.
   */
  public static function editRoutes(): array {
    return [
      'live preview' => ['canvas_view_template.live_preview', ['view_template' => 'sample']],
    ];
  }

  /**
   * Only users who manage templates reach the panel routes.
   */
  #[DataProvider('adminRoutes')]
  public function testAdminRoutes(string $route, array $parameters): void {
    self::assertFalse($this->accessManager->checkNamedRoute($route, $parameters, new AnonymousUserSession()), 'anonymous');
    self::assertFalse($this->accessManager->checkNamedRoute($route, $parameters, $this->plainUser), 'authenticated without the permission');
    // The duplicate route also needs a CSRF token, which a named-route check
    // has no request to read: see testDuplicateNeedsCsrfToken().
    if ($route !== 'canvas_view_template.duplicate') {
      self::assertTrue($this->accessManager->checkNamedRoute($route, $parameters, $this->admin), 'administer view templates');
    }
  }

  /**
   * The live preview follows edit access to the template.
   */
  #[DataProvider('editRoutes')]
  public function testEditRoutes(string $route, array $parameters): void {
    self::assertFalse($this->accessManager->checkNamedRoute($route, $parameters, new AnonymousUserSession()), 'anonymous');
    self::assertFalse($this->accessManager->checkNamedRoute($route, $parameters, $this->plainUser), 'authenticated without the permission');
    self::assertTrue($this->accessManager->checkNamedRoute($route, $parameters, $this->admin), 'administer view templates');
  }

  /**
   * Every route the module defines is covered by one of the lists above.
   */
  public function testEveryRouteIsCovered(): void {
    $covered = \array_merge(
      \array_column(\array_values(self::adminRoutes()), 0),
      \array_column(\array_values(self::editRoutes()), 0),
      // Canvas's component form: see testComponentFormRoute().
      ['canvas.api.form.component_instance.canvas_view_template'],
    );
    $file = \dirname(__DIR__, 3) . '/canvas_view_template.routing.yml';
    $defined = \array_keys(Yaml::decode((string) \file_get_contents($file)));
    \sort($covered);
    \sort($defined);
    self::assertSame($defined, $covered, 'A new route needs an access test here.');
  }

  /**
   * Duplicate rejects a missing or wrong CSRF token, and takes the right one.
   */
  public function testDuplicateNeedsCsrfToken(): void {
    $make = function (?string $token): Request {
      $request = Request::create('/canvas-view-template/duplicate/sample', 'POST');
      // The check only applies to requests that carry a session cookie.
      $name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request->cookies->set($name, 'session');
      if ($token !== NULL) {
        $request->headers->set('X-CSRF-Token', $token);
      }
      $route = $this->container->get('router.route_provider')->getRouteByName('canvas_view_template.duplicate');
      $request->attributes->add([
        RouteObjectInterface::ROUTE_NAME => 'canvas_view_template.duplicate',
        RouteObjectInterface::ROUTE_OBJECT => $route,
        'view_template' => ViewTemplate::load('sample'),
        '_raw_variables' => new InputBag(['view_template' => 'sample']),
      ]);
      return $request;
    };
    $check = fn (?string $token, AccountInterface $account): bool => $this->accessManager->checkRequest($make($token), $account);

    self::assertFalse($check(NULL, $this->admin), 'No token.');
    self::assertFalse($check('not-a-token', $this->admin), 'A wrong token.');
    $valid = $this->container->get('csrf_token')->get('rest');
    self::assertTrue($check($valid, $this->admin), 'The right token.');
    self::assertFalse($check($valid, $this->plainUser), 'The right token is not enough without the permission.');
    self::assertFalse($check($valid, new AnonymousUserSession()), 'Anonymous.');
  }

  /**
   * Only the state-changing routes declare CSRF protection.
   */
  public function testOnlyStateChangingRoutesRequireCsrf(): void {
    $provider = $this->container->get('router.route_provider');
    $names = \array_merge(\array_column(\array_values(self::adminRoutes()), 0), \array_column(\array_values(self::editRoutes()), 0));
    foreach ($names as $name) {
      $route = $provider->getRouteByName($name);
      $unsafe = \array_diff($route->getMethods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
      self::assertSame($unsafe, $route->hasRequirement('_csrf_request_header_token'), $name);
    }
  }

  /**
   * Canvas's component form route needs edit access on the template.
   */
  public function testComponentFormRoute(): void {
    $parameters = ['entity' => 'sample'];
    $route = 'canvas.api.form.component_instance.canvas_view_template';
    // Canvas answers an anonymous visitor with a 401, not a plain denial.
    try {
      $this->accessManager->checkNamedRoute($route, $parameters, new AnonymousUserSession());
      self::fail('Anonymous visitors must not reach the component form.');
    }
    catch (UnauthorizedHttpException) {
      // Expected.
    }
    self::assertFalse($this->accessManager->checkNamedRoute($route, $parameters, $this->plainUser), 'authenticated without the permission');
    self::assertTrue($this->accessManager->checkNamedRoute($route, $parameters, $this->admin), 'administer view templates');
  }

}
