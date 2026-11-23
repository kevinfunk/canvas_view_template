<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Unit;

use Drupal\canvas_view_template\Hook\ViewTemplateSchemaHooks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the change to Canvas's list of allowed component sources.
 */
#[CoversClass(ViewTemplateSchemaHooks::class)]
#[Group('canvas_view_template')]
final class ViewTemplateSchemaHooksTest extends UnitTestCase {

  /**
   * Canvas's schema, reduced to the part the hook changes.
   *
   * @param array<int, string>|null $choices
   *   The allowed sources, or NULL for no `Choice` constraint at all.
   *
   * @return array<string, mixed>
   *   Schema definitions.
   */
  private static function definitions(?array $choices): array {
    $constraints = ['Regex' => ['pattern' => '/^[a-z0-9_]+$/']];
    if ($choices !== NULL) {
      $constraints['Choice'] = ['choices' => $choices];
    }
    return ['canvas.component.*' => ['mapping' => ['source' => ['type' => 'string', 'constraints' => $constraints]]]];
  }

  /**
   * This module's sources are added, and Canvas's own stay.
   */
  public function testAddsOurSourcesAndKeepsTheOthers(): void {
    $definitions = self::definitions(['sdc', 'js', 'block', 'marker']);
    ViewTemplateSchemaHooks::configSchemaInfoAlter($definitions);
    $choices = $definitions['canvas.component.*']['mapping']['source']['constraints']['Choice']['choices'];
    self::assertSame(['sdc', 'js', 'block', 'marker'], \array_slice($choices, 0, 4));
    foreach (ViewTemplateSchemaHooks::sourcePluginIds() as $id) {
      self::assertContains($id, $choices);
    }
    // Everything else is still refused, and the other constraints are intact.
    self::assertNotContains('anything_else', $choices);
    self::assertSame(['pattern' => '/^[a-z0-9_]+$/'], $definitions['canvas.component.*']['mapping']['source']['constraints']['Regex']);
  }

  /**
   * Running it twice does not repeat the sources.
   */
  public function testIsSafeToRunTwice(): void {
    $definitions = self::definitions(['sdc']);
    ViewTemplateSchemaHooks::configSchemaInfoAlter($definitions);
    $once = $definitions;
    ViewTemplateSchemaHooks::configSchemaInfoAlter($definitions);
    self::assertSame($once, $definitions);
  }

  /**
   * Without the list (dev mode removed it) nothing is added or invented.
   */
  public function testDoesNothingWhenThereIsNoList(): void {
    $definitions = self::definitions(NULL);
    $before = $definitions;
    ViewTemplateSchemaHooks::configSchemaInfoAlter($definitions);
    self::assertSame($before, $definitions);
  }

  /**
   * A schema of another shape is left alone.
   */
  public function testDoesNothingWhenTheSchemaHasChanged(): void {
    $definitions = [
      'canvas.component.*' => ['mapping' => ['label' => ['type' => 'label']]],
      'other' => ['type' => 'string'],
    ];
    $before = $definitions;
    ViewTemplateSchemaHooks::configSchemaInfoAlter($definitions);
    self::assertSame($before, $definitions);
    $empty = [];
    ViewTemplateSchemaHooks::configSchemaInfoAlter($empty);
    self::assertSame([], $empty);
  }

  /**
   * Every source this module defines is listed, with none missing or extra.
   */
  public function testListsEveryPluginSource(): void {
    $ids = ViewTemplateSchemaHooks::sourcePluginIds();
    self::assertSame(\array_values(\array_unique($ids)), $ids);
    self::assertCount(9, $ids);
    foreach ($ids as $id) {
      self::assertStringStartsWith('canvas_view_template_', $id);
    }
  }

}
