<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Unit;

use Drupal\canvas_view_template\Render\RowSet;
use Drupal\canvas_view_template\Render\ViewRenderData;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-row data bundle.
 */
#[Group('canvas_view_template')]
final class RowSetTest extends UnitTestCase {

  /**
   * Builds a set of four rows, each with its own render, field and link.
   */
  private function rows(): RowSet {
    return new RowSet(
      [['#markup' => 'zero'], 'one', ['#markup' => 'two'], 'three'],
      [
        'labels' => ['title' => 'Title'],
        'rows' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C'], ['title' => 'D']],
      ],
      ['/a', '/b', NULL, '/d'],
    );
  }

  /**
   * It takes the three per-row pieces from what a View produced.
   */
  public function testFromData(): void {
    $set = RowSet::fromData(new ViewRenderData(rowRenders: ['x'], fieldData: ['rows' => [['f' => 1]]], itemUrls: ['/x']));
    self::assertSame(['x'], $set->renders);
    self::assertSame(['rows' => [['f' => 1]]], $set->fields);
    self::assertSame(['/x'], $set->urls);
  }

  /**
   * A slice keeps render, field and link of each row together.
   */
  public function testSliceKeepsRowsAligned(): void {
    $slice = $this->rows()->slice(1, 2);
    self::assertSame(['#markup' => 'one'], $slice->render(0));
    self::assertSame(['value' => 'B', 'label' => 'Title'], $slice->fieldValue(0, 'title'));
    self::assertSame('/b', $slice->url(0));
    self::assertSame(['#markup' => 'two'], $slice->render(1));
    self::assertSame(['value' => 'C', 'label' => 'Title'], $slice->fieldValue(1, 'title'));
    self::assertNull($slice->url(1));
    self::assertSame([0, 1], \array_keys($slice->renders));
    self::assertSame([], $slice->fieldValue(2, 'title'));
  }

  /**
   * Labels survive slicing, and a slice past the end is empty.
   */
  public function testSliceEdges(): void {
    self::assertSame(['title' => 'Title'], $this->rows()->slice(0, 1)->fields['labels']);
    self::assertSame([], $this->rows()->slice(10, 5)->renders);
    self::assertSame([], (new RowSet())->slice(0, 5)->fields['rows']);
    // A NULL limit means no limit, as for the repeater's "0".
    self::assertCount(3, $this->rows()->slice(1, NULL)->renders);
  }

  /**
   * A plain string render is wrapped, and a missing row is empty.
   */
  public function testRender(): void {
    self::assertSame(['#markup' => 'three'], $this->rows()->render(3));
    self::assertSame([], $this->rows()->render(9));
  }

  /**
   * Missing or empty field ids give an empty result.
   */
  public function testFieldValueGuards(): void {
    $rows = $this->rows();
    self::assertSame([], $rows->fieldValue(0, NULL));
    self::assertSame([], $rows->fieldValue(0, ''));
    self::assertSame([], $rows->fieldValue(0, 'missing'));
    self::assertSame(['value' => 'A', 'label' => ''], (new RowSet([], ['rows' => [['x' => 'A']]]))->fieldValue(0, 'x'));
  }

}
