<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\Canvas\ComponentSource;

use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Shared limit/offset/page-condition settings for repeater components.
 */
trait ViewTemplatePageConditionTrait {

  /**
   * Page condition: every pager page (the default).
   */
  public const string PAGE_CONDITION_ALL = 'all';

  /**
   * Page condition: only the specified page.
   */
  public const string PAGE_CONDITION_ONLY = 'only';

  /**
   * Page condition: every page except the specified one.
   */
  public const string PAGE_CONDITION_EXCEPT = 'except';

  /**
   * Builds the limit/offset/"Show on"/page number form elements.
   *
   * @param string $limit_key
   *   The limit input key.
   * @param string $offset_key
   *   The offset input key.
   * @param string $mode_key
   *   The "Show on" input key.
   * @param string $page_key
   *   The page number input key.
   * @param array $inputValues
   *   The current stored inputs.
   *
   * @return array
   *   Form elements keyed by the four input keys.
   */
  protected function buildLimitOffsetPageConditionFormElements(string $limit_key, string $offset_key, string $mode_key, string $page_key, array $inputValues): array {
    $form = [];
    $form[$limit_key] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items'),
      '#description' => $this->t('How many items to repeat. Leave empty to repeat every remaining item.'),
      '#min' => 1,
      '#default_value' => $inputValues[$limit_key] ?? NULL,
    ];
    $form[$offset_key] = [
      '#type' => 'number',
      '#title' => $this->t('Offset'),
      '#description' => $this->t('How many leading items to skip before repeating starts.'),
      '#min' => 0,
      '#default_value' => $inputValues[$offset_key] ?? 0,
    ];
    $form[$mode_key] = [
      '#type' => 'select',
      '#title' => $this->t('Show on'),
      '#description' => $this->t('Which pager page(s) this is active on — unlike "Offset" above, which only slices items within whichever page is currently showing, this applies across pagination.'),
      '#options' => [
        self::PAGE_CONDITION_ALL => $this->t('All pages'),
        self::PAGE_CONDITION_ONLY => $this->t('Only page'),
        self::PAGE_CONDITION_EXCEPT => $this->t('All pages except'),
      ],
      '#default_value' => $inputValues[$mode_key] ?? self::PAGE_CONDITION_ALL,
    ];
    // No #states: it needs a custom #name, which Canvas's client form
    // serialization ignores, so the selection would not be saved.
    $form[$page_key] = [
      '#type' => 'number',
      '#title' => $this->t('Page number'),
      '#description' => $this->t("The pager's page number, starting at 1 for the first page. Only used when \"Show on\" above is set to \"Only page\" or \"All pages except\"."),
      '#min' => 1,
      '#default_value' => $inputValues[$page_key] ?? NULL,
    ];
    return $form;
  }

  /**
   * Validates the limit/offset/page-condition settings.
   *
   * @param array $inputValues
   *   The submitted input values.
   * @param string $limit_key
   *   The limit input key.
   * @param string $offset_key
   *   The offset input key.
   * @param string $mode_key
   *   The "Show on" input key.
   * @param string $page_key
   *   The page number input key.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The violation list to append to.
   */
  protected function validateLimitOffsetPageCondition(array $inputValues, string $limit_key, string $offset_key, string $mode_key, string $page_key, ConstraintViolationList $violations): void {
    $limit = $inputValues[$limit_key] ?? NULL;
    if ($limit !== NULL && $limit < 1) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Number of items must be at least 1, or left empty.'),
        NULL,
        [],
        $inputValues,
        $limit_key,
        $limit,
      ));
    }
    $offset = $inputValues[$offset_key] ?? 0;
    if ($offset < 0) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('Offset must be 0 or greater.'),
        NULL,
        [],
        $inputValues,
        $offset_key,
        $offset,
      ));
    }
    $mode = $inputValues[$mode_key] ?? self::PAGE_CONDITION_ALL;
    $page = $inputValues[$page_key] ?? NULL;
    if (\in_array($mode, [self::PAGE_CONDITION_ONLY, self::PAGE_CONDITION_EXCEPT], TRUE) && ($page === NULL || $page < 1)) {
      $violations->add(new ConstraintViolation(
        (string) $this->t('A page number is required when "Show on" is set to "Only page" or "All pages except".'),
        NULL,
        [],
        $inputValues,
        $page_key,
        $page,
      ));
    }
  }

  /**
   * Converts a client `resolved` model to stored input.
   *
   * @param array $resolved
   *   The client model's `resolved` values.
   * @param string $limit_key
   *   The limit input key.
   * @param string $offset_key
   *   The offset input key.
   * @param string $mode_key
   *   The "Show on" input key.
   * @param string $page_key
   *   The page number input key.
   *
   * @return array
   *   The stored input, with $limit_key/$page_key omitted (not NULL) when
   *   unset, as `requiredKey: false` requires.
   */
  protected function limitOffsetPageConditionToInput(array $resolved, string $limit_key, string $offset_key, string $mode_key, string $page_key): array {
    $input = [
      $offset_key => (int) ($resolved[$offset_key] ?? 0),
      $mode_key => $resolved[$mode_key] ?? self::PAGE_CONDITION_ALL,
    ];
    $limit = $resolved[$limit_key] ?? NULL;
    if ($limit !== NULL && $limit !== '') {
      $input[$limit_key] = (int) $limit;
    }
    $page = $resolved[$page_key] ?? NULL;
    if ($page !== NULL && $page !== '') {
      $input[$page_key] = (int) $page;
    }
    return $input;
  }

}
