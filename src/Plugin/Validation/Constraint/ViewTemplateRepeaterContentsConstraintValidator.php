<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateEmpty;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateFeedIcons;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateSummary;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateTitle;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a "View repeater" holds no whole-view components.
 */
final class ViewTemplateRepeaterContentsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ViewTemplateRepeaterContentsConstraint);

    foreach (self::misplacedComponents(ViewTemplateTreeItems::from($value)) as $uuid => $component_id) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@component', (string) self::labels()[$component_id])
        ->setInvalidValue((string) $uuid)
        ->addViolation();
    }
  }

  /**
   * The components that show the whole view, with their names.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels, keyed by full Component config entity id.
   */
  public static function labels(): array {
    $placeholder = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.';
    return [
      $placeholder . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID => new TranslatableMarkup('View rows'),
      $placeholder . ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID => new TranslatableMarkup('View exposed filters'),
      $placeholder . ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID => new TranslatableMarkup('View pager'),
      $placeholder . ViewTemplatePlaceholder::VIEW_HEADER_LOCAL_ID => new TranslatableMarkup('View header'),
      $placeholder . ViewTemplatePlaceholder::VIEW_FOOTER_LOCAL_ID => new TranslatableMarkup('View footer'),
      $placeholder . ViewTemplatePlaceholder::VIEW_MORE_LOCAL_ID => new TranslatableMarkup('View more link'),
      $placeholder . ViewTemplatePlaceholder::VIEW_ATTACHMENTS_LOCAL_ID => new TranslatableMarkup('View attachments'),
      ViewTemplateEmpty::SOURCE_PLUGIN_ID . '.' . ViewTemplateEmpty::LOCAL_ID => new TranslatableMarkup('View no results'),
      ViewTemplateTitle::SOURCE_PLUGIN_ID . '.' . ViewTemplateTitle::LOCAL_ID => new TranslatableMarkup('View title'),
      ViewTemplateSummary::SOURCE_PLUGIN_ID . '.' . ViewTemplateSummary::LOCAL_ID => new TranslatableMarkup('View result summary'),
      ViewTemplateFeedIcons::SOURCE_PLUGIN_ID . '.' . ViewTemplateFeedIcons::LOCAL_ID => new TranslatableMarkup('View feed icons'),
    ];
  }

  /**
   * Finds the whole-view components placed inside a repeater, at any depth.
   *
   * @param array<int, mixed> $items
   *   The tree's items.
   *
   * @return array<string, string>
   *   Component ids, keyed by item UUID.
   */
  public static function misplacedComponents(array $items): array {
    return \array_intersect(ViewTemplateTreeItems::insideRepeater($items), \array_keys(self::labels()));
  }

}
