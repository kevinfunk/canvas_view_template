<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the "View title" component's settings.
 */
#[CoversClass(ViewTemplateTitle::class)]
#[Group('canvas_view_template')]
final class ViewTemplateTitleTest extends ViewTemplateKernelTestBase {

  /**
   * The "View title" component.
   */
  private Component $component;

  /**
   * Its component source.
   */
  private ViewTemplateTitle $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $component = Component::load(ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID);
    self::assertNotNull($component);
    $this->component = $component;
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateTitle::class, $source);
    $this->source = $source;
  }

  /**
   * The default element is h2, not h1, as the page title is usually the h1.
   */
  public function testDefaultElementIsH2(): void {
    self::assertSame([ViewTemplateTitle::INPUT_ELEMENT => 'h2'], $this->source->getDefaultExplicitInput());
  }

  /**
   * The form offers h1-h6 and plain text, preselecting the stored value.
   */
  public function testFormOffersAllElements(): void {
    $form = [];
    $built = $this->source->buildComponentInstanceForm($form, new FormState(), $this->component, 'uuid', [ViewTemplateTitle::INPUT_ELEMENT => 'h4']);

    $element = $built[ViewTemplateTitle::INPUT_ELEMENT];
    self::assertSame('select', $element['#type']);
    self::assertSame(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'none'], \array_keys($element['#options']));
    self::assertSame('h4', $element['#default_value']);
    // A custom #name would not survive the client's form serialization.
    self::assertArrayNotHasKey('#name', $element);
  }

  /**
   * A new instance gets the default; an explicit choice is kept.
   */
  public function testClientModelToInput(): void {
    self::assertSame(
      ['element' => 'h2'],
      $this->source->clientModelToInput('uuid', $this->component, ['resolved' => []], NULL),
    );
    self::assertSame(
      ['element' => 'h5'],
      $this->source->clientModelToInput('uuid', $this->component, ['resolved' => ['element' => 'h5']], NULL),
    );
  }

  /**
   * Only the offered elements validate.
   */
  public function testValidation(): void {
    foreach (['h1', 'h6', 'none'] as $valid) {
      self::assertCount(0, $this->source->validateComponentInput(['element' => $valid], 'uuid', NULL), $valid);
    }
    foreach (['h7', 'script', '', 5] as $invalid) {
      self::assertCount(1, $this->source->validateComponentInput(['element' => $invalid], 'uuid', NULL), (string) $invalid);
    }
  }

}
