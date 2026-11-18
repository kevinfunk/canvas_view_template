<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Forbids components showing the whole view inside a "View repeater".
 *
 * A repeater renders its contents once per item, where those components
 * would repeat or show nothing.
 */
#[Constraint(
  id: 'ViewTemplateRepeaterContents',
  label: new TranslatableMarkup('View template has only per-item components inside a "View repeater"', [], ['context' => 'Validation']),
)]
final class ViewTemplateRepeaterContentsConstraint extends SymfonyConstraint {

  /**
   * The violation message shown for each misplaced component.
   */
  public string $message = 'A "@component" cannot be placed inside a "View repeater": it shows the whole view, not one item. Place it beside the repeater.';

}
