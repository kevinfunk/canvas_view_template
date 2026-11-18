<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires at most one "View rows" placeholder in a view template's tree.
 *
 * Zero is fine (a new template starts empty); more than one is ambiguous.
 * ComponentTreeMeetRequirements cannot express "at most one".
 */
#[Constraint(
  id: 'ViewTemplateAtMostOneRows',
  label: new TranslatableMarkup('View template has at most one "View rows" placement', [], ['context' => 'Validation']),
)]
final class ViewTemplateAtMostOneRowsConstraint extends SymfonyConstraint {

  /**
   * The violation message shown when more than one placeholder is present.
   */
  public string $multipleMessage = 'A view template must contain only one "View rows" placement, but found @count.';

}
