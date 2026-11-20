<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Form\ViewTemplateComponentInstanceForm;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the forked component instance form used for view templates.
 *
 * @see \Drupal\canvas_view_template\Form\ViewTemplateComponentInstanceForm
 */
#[CoversClass(ViewTemplateComponentInstanceForm::class)]
#[Group('canvas_view_template')]
final class ViewTemplateComponentInstanceFormTest extends ViewTemplateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...ViewTemplateKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
  }

  /**
   * View template URLs reach this form, not Canvas's generic one.
   */
  public function testRouteResolvesToForkedForm(): void {
    ViewTemplate::create(['id' => 'vt_route', 'label' => 'Route'])->save();

    $request = Request::create('/canvas/api/v0/form/component-instance/canvas_view_template/vt_route', 'PATCH');
    $this->container->get('router.request_context')->fromRequest($request);
    $match = $this->container->get('router.no_access_checks')->matchRequest($request);

    self::assertSame('canvas.api.form.component_instance.canvas_view_template', $match['_route']);
    self::assertSame(ViewTemplateComponentInstanceForm::class, $match['_form']);
    self::assertInstanceOf(ViewTemplate::class, $match['entity']);
  }

  /**
   * The form builds for a view template with assertions enabled.
   *
   * Canvas's own form fails its entity-type assert() for this entity type.
   */
  public function testBuildFormForViewTemplate(): void {
    self::assertSame('1', \ini_get('zend.assertions'), 'This test only means something with assertions enabled.');
    $this->container->get('theme_installer')->install(['canvas_stark']);

    $view_template = ViewTemplate::create(['id' => 'vt_form', 'label' => 'Form']);
    $view_template->save();
    $component = Component::load('canvas_view_template_repeater.view_repeater');
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    $uuid = '11111111-1111-4111-8111-111111111111';

    $request = Request::create('/canvas/api/v0/form/component-instance/canvas_view_template/vt_form', 'POST', [
      'form_canvas_tree' => \json_encode(['type' => $component->id() . '@' . $component->getActiveVersion()]),
      'form_canvas_selected' => $uuid,
      'form_canvas_props' => \json_encode($source->inputToClientModel($source->getDefaultExplicitInput())),
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    // buildForm() takes the entity as an extra argument.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$view_template]);
    $form = $this->container->get('form_builder')->buildForm(ViewTemplateComponentInstanceForm::class, $form_state);

    self::assertArrayHasKey($uuid, $form['canvas_component_props']);
  }

  /**
   * A damaged component tree is a bad request, not a server error.
   */
  #[DataProvider('badTreeProvider')]
  public function testBadComponentTreeIsBadRequest(string $tree): void {
    $this->container->get('theme_installer')->install(['canvas_stark']);
    $view_template = ViewTemplate::create(['id' => 'vt_bad', 'label' => 'Bad']);
    $view_template->save();

    $request = Request::create('/canvas/api/v0/form/component-instance/canvas_view_template/vt_bad', 'POST', [
      'form_canvas_tree' => $tree,
      'form_canvas_selected' => '11111111-1111-4111-8111-111111111111',
      'form_canvas_props' => '{}',
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$view_template]);
    $this->expectException(BadRequestHttpException::class);
    $this->container->get('form_builder')->buildForm(ViewTemplateComponentInstanceForm::class, $form_state);
  }

  /**
   * Trees the form must reject.
   *
   * @return array<string, array{string}>
   *   Values for `form_canvas_tree`.
   */
  public static function badTreeProvider(): array {
    return [
      'not JSON' => ['not json'],
      'no type' => ['{}'],
      'type is not a string' => ['{"type": 5}'],
      'no version' => ['{"type": "canvas_view_template_repeater.view_repeater"}'],
      'unknown component' => ['{"type": "nothing.here@abc"}'],
    ];
  }

}
