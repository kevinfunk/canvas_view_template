<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a view template tree has at most one "View rows" placeholder.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraintValidator
 */
final class ViewTemplateAtMostOneRowsConstraintValidator extends ConstraintValidator {

  /**
   * The full Component config entity id of the "View rows" placeholder.
   */
  public const string VIEW_ROWS_COMPONENT_ID = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ViewTemplateAtMostOneRowsConstraint);

    $placeholder_count = \count(\array_filter(
      \array_column(ViewTemplateTreeItems::from($value), 'component_id'),
      static fn (string $component_id): bool => $component_id === self::VIEW_ROWS_COMPONENT_ID,
    ));

    if ($placeholder_count > 1) {
      $this->context->buildViolation($constraint->multipleMessage)
        ->setParameter('@count', (string) $placeholder_count)
        ->addViolation();
    }
  }

}
