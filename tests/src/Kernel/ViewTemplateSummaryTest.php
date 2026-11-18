<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Render\ViewResultSummary;
use Drupal\canvas_view_template\Render\ViewTemplateRenderer;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests "View result summary": for example "Displaying 1 - 10 of 54".
 */
#[CoversClass(ViewTemplateSummary::class)]
#[CoversClass(ViewResultSummary::class)]
#[Group('canvas_view_template')]
final class ViewTemplateSummaryTest extends ViewTemplateKernelTestBase {

  /**
   * The "View result summary" ComponentSource plugin instance.
   */
  private ViewTemplateSummary $source;

  /**
   * The numbers for the second of three pages of ten.
   */
  private const array TOKENS = [
    '@start' => '11',
    '@end' => '20',
    '@total' => '25',
    '@label' => 'Articles',
    '@per_page' => '10',
    '@current_page' => '2',
    '@current_record_count' => '10',
    '@page_count' => '3',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $component = Component::load(ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID);
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(ViewTemplateSummary::class, $source);
    $this->source = $source;
  }

  /**
   * Renders a template with one summary.
   *
   * @param array $inputs
   *   The summary's settings.
   * @param array $tokens
   *   The numbers, or an empty array for a View with no results.
   */
  private function renderSummary(array $inputs, array $tokens): string {
    $component = Component::load(ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID);
    self::assertNotNull($component);
    $view_template = ViewTemplate::create([
      'id' => 'summary_test',
      'label' => 'Summary test',
      'component_tree' => [[
        'uuid' => \Drupal::service('uuid')->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
      ],
    ]);
    $result = $this->container->get(ViewTemplateRenderer::class)->render($view_template, [], '', '', [], 0, [], [], $tokens);
    return (string) $this->renderer->renderInIsolation($result);
  }

  /**
   * The default text, in a paragraph.
   */
  public function testDefault(): void {
    $html = $this->renderSummary($this->source->getDefaultExplicitInput(), self::TOKENS);
    self::assertMatchesRegularExpression('#<p>\s*Displaying 11 - 20 of 25\s*</p>#', $html);
  }

  /**
   * Every token is replaced, in a format of your own.
   */
  public function testEveryToken(): void {
    $format = '@label: @start-@end of @total, @current_record_count here, page @current_page of @page_count, @per_page per page';
    $html = $this->renderSummary([ViewTemplateSummary::INPUT_FORMAT => $format], self::TOKENS);
    self::assertStringContainsString('Articles: 11-20 of 25, 10 here, page 2 of 3, 10 per page', $html);
    self::assertStringNotContainsString('@', $html);
  }

  /**
   * The element wraps the text, or not at all.
   */
  public function testElement(): void {
    $span = $this->renderSummary([ViewTemplateSummary::INPUT_ELEMENT => 'span'], self::TOKENS);
    self::assertMatchesRegularExpression('#<span>\s*Displaying#', $span);
    $none = $this->renderSummary([ViewTemplateSummary::INPUT_ELEMENT => ViewTemplateSummary::ELEMENT_NONE], self::TOKENS);
    self::assertStringContainsString('Displaying 11 - 20 of 25', $none);
    self::assertStringNotContainsString('<p>', $none);
    self::assertStringNotContainsString('<span>', $none);
  }

  /**
   * The text is escaped: the summary can't output markup.
   */
  public function testTextIsEscaped(): void {
    $html = $this->renderSummary([ViewTemplateSummary::INPUT_FORMAT => '<script>alert(1)</script> @total'], self::TOKENS);
    self::assertStringNotContainsString('<script>', $html);
    self::assertStringContainsString('&lt;script&gt;', $html);
  }

  /**
   * A View with no results has no summary.
   */
  public function testNothingWithoutResults(): void {
    $html = $this->renderSummary($this->source->getDefaultExplicitInput(), []);
    self::assertStringNotContainsString('Displaying', $html);
    self::assertStringNotContainsString('<p>', $html);
  }

  /**
   * Stored settings are validated.
   */
  public function testValidateComponentInput(): void {
    self::assertCount(0, $this->source->validateComponentInput($this->source->getDefaultExplicitInput(), 'u', NULL));
    self::assertCount(1, $this->source->validateComponentInput([ViewTemplateSummary::INPUT_ELEMENT => 'script'], 'u', NULL));
    self::assertCount(1, $this->source->validateComponentInput([ViewTemplateSummary::INPUT_FORMAT => \str_repeat('x', 256)], 'u', NULL));
  }

  /**
   * A new instance gets the defaults, and the form lists the tokens.
   */
  public function testDefaultsAndForm(): void {
    $component = Component::load(ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID);
    self::assertSame($this->source->getDefaultExplicitInput(), $this->source->clientModelToInput('u', $component, ['resolved' => []], NULL));

    $form = $this->source->buildComponentInstanceForm([], new FormState(), $component, 'u', []);
    self::assertSame('textfield', $form[ViewTemplateSummary::INPUT_FORMAT]['#type']);
    self::assertSame(ViewTemplateSummary::DEFAULT_FORMAT, $form[ViewTemplateSummary::INPUT_FORMAT]['#default_value']);
    foreach (\array_keys(ViewResultSummary::tokens()) as $token) {
      self::assertStringContainsString($token, (string) $form[ViewTemplateSummary::INPUT_FORMAT]['#description']);
    }
    self::assertSame('select', $form[ViewTemplateSummary::INPUT_ELEMENT]['#type']);
    // Custom names would not survive the client's form serialization.
    self::assertArrayNotHasKey('#name', $form[ViewTemplateSummary::INPUT_FORMAT]);
    self::assertArrayNotHasKey('#name', $form[ViewTemplateSummary::INPUT_ELEMENT]);
  }

}
