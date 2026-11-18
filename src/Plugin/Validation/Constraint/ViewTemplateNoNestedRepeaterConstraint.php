<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Forbids a "View repeater" inside another "View repeater".
 *
 * A nested repeater has no items of its own to repeat, so it would only ever
 * show nothing.
 */
#[Constraint(
  id: 'ViewTemplateNoNestedRepeater',
  label: new TranslatableMarkup('View template has no "View repeater" inside another', [], ['context' => 'Validation']),
)]
final class ViewTemplateNoNestedRepeaterConstraint extends SymfonyConstraint {

  /**
   * The violation message shown for each nested repeater.
   */
  public string $nestedMessage = 'A "View repeater" cannot be placed inside another "View repeater". Place it beside it, or use "View item" and "View field" inside the first one.';

}
