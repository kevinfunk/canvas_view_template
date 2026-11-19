<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\Core\Form\FormState;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fields offered when no View is chosen for the preview.
 */
#[CoversClass(ViewTemplateField::class)]
#[Group('canvas_view_template')]
final class ViewTemplateFieldChoicesTest extends ViewTemplateKernelTestBase {

  /**
   * Fields come from the enabled Views' default display, without building them.
   */
  public function testFieldsComeFromRawConfig(): void {
    $this->createView('cvt_articles', TRUE, [
      'cvt_title' => ['id' => 'cvt_title', 'label' => '', 'admin_label' => 'Headline'],
      'cvt_body' => ['id' => 'cvt_body', 'label' => 'Text'],
      'cvt_nid' => ['id' => 'cvt_nid', 'label' => ''],
    ]);
    $this->createView('cvt_people', TRUE, [
      'cvt_title' => ['id' => 'cvt_title', 'label' => 'Name'],
      'cvt_mail' => ['id' => 'cvt_mail', 'label' => 'Email'],
    ]);
    $this->createView('cvt_hidden', FALSE, ['cvt_secret' => ['id' => 'cvt_secret', 'label' => 'Secret']]);
    ViewTemplate::create(['id' => 'unused', 'label' => 'Unused'])->save();

    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    $source = $component->getComponentSource();
    $form = $source->buildComponentInstanceForm([], new FormState(), $component, 'u', [], ViewTemplate::load('unused'));

    self::assertSame('select', $form[ViewTemplateField::INPUT_FIELD]['#type']);
    // Other Views on the site add their fields, so only ours are compared.
    $options = \array_filter(
      $form[ViewTemplateField::INPUT_FIELD]['#options'],
      static fn (string $key): bool => \str_starts_with($key, 'cvt_'),
      ARRAY_FILTER_USE_KEY,
    );
    // The admin label wins over the label; with neither, the id is shown. The
    // first View listing a field names it. Disabled Views offer nothing.
    \ksort($options);
    self::assertSame([
      'cvt_body' => 'Text',
      'cvt_mail' => 'Email',
      'cvt_nid' => 'cvt_nid',
      'cvt_title' => 'Headline',
    ], $options);
  }

  /**
   * Creates a View whose default display has the given fields.
   */
  private function createView(string $id, bool $status, array $fields): void {
    // The handler keys Views needs to compute the View's dependencies.
    $handler = ['table' => 'node_field_data', 'field' => 'title', 'plugin_id' => 'standard'];
    $fields = \array_map(static fn (array $field): array => $field + $handler, $fields);
    View::create([
      'id' => $id,
      'label' => $id,
      'status' => $status,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'display_options' => ['fields' => $fields],
        ],
      ],
    ])->save();
  }

}
