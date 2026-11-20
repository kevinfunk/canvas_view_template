<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

/**
 * The per-row data of a View's results, kept together.
 *
 * Index N means the same row in all three lists. Slicing keeps them aligned.
 *
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer
 */
final class RowSet {

  /**
   * Creates the set.
   *
   * @param array<int, array|string> $renders
   *   Each row rendered individually.
   * @param array $fields
   *   The rows' fields (`rows`) and labels (`labels`).
   * @param array<int, string|null> $urls
   *   The page each row links to.
   */
  public function __construct(
    public readonly array $renders = [],
    public readonly array $fields = [],
    public readonly array $urls = [],
  ) {}

  /**
   * Takes the per-row pieces of what a View produced.
   */
  public static function fromData(ViewRenderData $data): self {
    return new self($data->rowRenders, $data->fieldData, $data->itemUrls);
  }

  /**
   * The rows from $offset on, at most $limit, counted again from 0.
   *
   * A NULL limit means no limit.
   */
  public function slice(int $offset, ?int $limit): self {
    return new self(
      \array_slice($this->renders, $offset, $limit),
      [
        'labels' => $this->fields['labels'] ?? [],
        'rows' => \array_slice($this->fields['rows'] ?? [], $offset, $limit),
      ],
      \array_slice($this->urls, $offset, $limit),
    );
  }

  /**
   * One row's render array; empty if there is no such row.
   */
  public function render(int $index): array {
    $row = $this->renders[$index] ?? [];
    return \is_array($row) ? $row : ['#markup' => $row];
  }

  /**
   * What "View field" resumes with for one row.
   *
   * @return array
   *   `value` and `label`, or an empty array if there is no such field.
   */
  public function fieldValue(int $index, ?string $field_id): array {
    if ($field_id === NULL || $field_id === '' || !isset($this->fields['rows'][$index][$field_id])) {
      return [];
    }
    return [
      'value' => $this->fields['rows'][$index][$field_id],
      'label' => $this->fields['labels'][$field_id] ?? '',
    ];
  }

  /**
   * The page one row links to, or NULL.
   */
  public function url(int $index): ?string {
    return $this->urls[$index] ?? NULL;
  }

}
