<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that no "View repeater" sits inside another, at any depth.
 */
final class ViewTemplateNoNestedRepeaterConstraintValidator extends ConstraintValidator {

  /**
   * The full Component config entity id of "View repeater".
   */
  public const string VIEW_REPEATER_COMPONENT_ID = ViewTemplateTreeItems::VIEW_REPEATER_COMPONENT_ID;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ViewTemplateNoNestedRepeaterConstraint);

    foreach (self::nestedRepeaterUuids(ViewTemplateTreeItems::from($value)) as $uuid) {
      $this->context->buildViolation($constraint->nestedMessage)
        ->setInvalidValue($uuid)
        ->addViolation();
    }
  }

  /**
   * Finds the repeaters that sit inside another repeater.
   *
   * @param array<int, mixed> $items
   *   The tree's items.
   *
   * @return list<string>
   *   The UUIDs of the nested repeaters.
   */
  public static function nestedRepeaterUuids(array $items): array {
    $inside = ViewTemplateTreeItems::insideRepeater($items);
    return \array_map('strval', \array_keys(\array_filter($inside, static fn (string $component_id): bool => $component_id === self::VIEW_REPEATER_COMPONENT_ID)));
  }

}
