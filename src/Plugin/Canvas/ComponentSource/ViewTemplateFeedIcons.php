<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides "View feed icons": the feed links of the Feed displays attached.
 *
 * "Label" can hide the feed title visually, but only in themes that mark it up
 * as `.feed-icon__label` (as Olivero does). The renderer resumes the Fiber with
 * the icons render array, or `[]` when no Feed display is attached.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::render()
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('View feed icons'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ViewTemplateFeedIcons extends ViewTemplateComponentSourceBase {

  public const string SOURCE_PLUGIN_ID = 'canvas_view_template_feed_icons';

  public const string LOCAL_ID = 'view_feed_icons';

  /**
   * The explicit input key for showing or hiding the label.
   */
  public const string INPUT_LABEL = 'label';

  public const string LABEL_VISIBLE = 'visible';

  public const string LABEL_HIDDEN = 'hidden';

  /**
   * The allowed "Label" values, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by value.
   */
  public static function labelOptions(): array {
    return [
      self::LABEL_VISIBLE => new TranslatableMarkup('Visible'),
      self::LABEL_HIDDEN => new TranslatableMarkup('Hidden'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('View feed icons');
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $label = $this->labelFrom($inputs[self::EXPLICIT_INPUT_NAME] ?? []);

    if (\Fiber::getCurrent() !== NULL) {
      $icons = \Fiber::suspend($this);
      if (\is_array($icons)) {
        if ($icons === []) {
          return [];
        }
        $classes = ['cvt-feed-icons'];
        if ($label === self::LABEL_HIDDEN) {
          $classes[] = 'cvt-feed-icons--label-hidden';
        }
        return [
          '#type' => 'container',
          '#attributes' => ['class' => $classes],
          'icons' => $icons,
          '#attached' => ['library' => ['canvas_view_template/feed_icons']],
        ];
      }
    }
    if ($isPreview) {
      return $this->buildMarkerStylePreview(
        'feed',
        $this->t('View feed icons'),
        $this->t("The view's feed icon appears here. Add a Feed display to the view and attach it to this display."),
        [self::labelOptions()[$label]],
      );
    }
    return [];
  }

  /**
   * Returns a valid label value from stored settings, or the default.
   */
  private function labelFrom(array $settings): string {
    $label = $settings[self::INPUT_LABEL] ?? self::LABEL_VISIBLE;
    return \is_string($label) && \array_key_exists($label, self::labelOptions()) ? $label : self::LABEL_VISIBLE;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [self::INPUT_LABEL => self::LABEL_VISIBLE];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $form[self::INPUT_LABEL] = [
      '#type' => 'select',
      '#title' => $this->t('Label'),
      '#description' => $this->t("The feed's title shown next to the icon. Hidden leaves just the icon, and keeps the title for screen readers. Only themes that mark the title up as a label (such as Olivero) show one."),
      '#options' => self::labelOptions(),
      '#default_value' => $this->labelFrom($inputValues),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $violations = new ConstraintViolationList();
    $label = $inputValues[self::INPUT_LABEL] ?? self::LABEL_VISIBLE;
    if (!\is_string($label) || !\array_key_exists($label, self::labelOptions())) {
      $violations->add($this->violation($this->t('Choose whether to show the label.'), $inputValues, self::INPUT_LABEL, $label));
    }
    return $violations;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [
      'type' => 'mapping',
      'mapping' => [
        self::INPUT_LABEL => ['type' => 'string'],
      ],
    ];
  }

}
