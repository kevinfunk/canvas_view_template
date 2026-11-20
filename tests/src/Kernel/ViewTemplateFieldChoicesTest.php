<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\ViewFieldChoices;
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

  /**
   * Fields of a View the user cannot access are not offered.
   */
  public function testFieldsOfRestrictedViewsAreHidden(): void {
    $this->createView('cvt_open', TRUE, ['cvt_open_field' => ['id' => 'cvt_open_field', 'label' => 'Open']]);
    $this->createView('cvt_closed', TRUE, ['cvt_closed_field' => ['id' => 'cvt_closed_field', 'label' => 'Closed']]);
    $closed = View::load('cvt_closed');
    $displays = $closed->get('display');
    $displays['default']['display_options']['access'] = ['type' => 'perm', 'options' => ['perm' => 'administer nodes']];
    $closed->set('display', $displays)->save();
    ViewTemplate::create(['id' => 'unused', 'label' => 'Unused'])->save();

    $component = Component::load(ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID);
    $form = $component->getComponentSource()->buildComponentInstanceForm([], new FormState(), $component, 'u', [], ViewTemplate::load('unused'));
    $options = $form[ViewTemplateField::INPUT_FIELD]['#options'];
    self::assertArrayHasKey('cvt_open_field', $options);
    self::assertArrayNotHasKey('cvt_closed_field', $options);
  }

  /**
   * With no preview chosen, only the Views using the template are offered.
   *
   * The preview falls back to the first display using the template, so the
   * source is the previewed display; `using` is for displays that cannot be
   * previewed, such as a feed.
   */
  public function testOnlyViewsUsingTheTemplateAreOffered(): void {
    $this->createView('cvt_other', TRUE, ['cvt_other_field' => ['id' => 'cvt_other_field', 'label' => 'Other']]);
    $this->createView('cvt_mine', TRUE, ['cvt_mine_field' => ['id' => 'cvt_mine_field', 'label' => 'Mine']]);
    $mine = View::load('cvt_mine');
    $displays = $mine->get('display');
    $displays['page_1'] = [
      'display_plugin' => 'page',
      'id' => 'page_1',
      'display_title' => 'Page',
      'position' => 1,
      'display_options' => [
        'path' => 'cvt-mine',
        'defaults' => ['style' => FALSE, 'row' => FALSE, 'fields' => FALSE],
        'style' => ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => 'uses_it']],
        'row' => ['type' => 'fields'],
        'fields' => $displays['default']['display_options']['fields'],
      ],
    ];
    $mine->set('display', $displays)->save();
    ViewTemplate::create(['id' => 'uses_it', 'label' => 'Used'])->save();
    ViewTemplate::create(['id' => 'unused', 'label' => 'Unused'])->save();

    $choices = $this->container->get(ViewFieldChoices::class);
    $previewed = $choices->forEntity(ViewTemplate::load('uses_it'));
    self::assertSame(ViewFieldChoices::SOURCE_PREVIEWED, $previewed['source']);
    // Labels come from the real handlers here; only the field names matter.
    self::assertSame(['cvt_mine_field'], \array_keys($previewed['options']));

    // A template used only by a display that cannot be previewed.
    $this->createView('cvt_feed', TRUE, ['cvt_feed_field' => ['id' => 'cvt_feed_field', 'label' => 'Feed']]);
    $feed = View::load('cvt_feed');
    $displays = $feed->get('display');
    $displays['feed_1'] = [
      'display_plugin' => 'feed',
      'id' => 'feed_1',
      'display_title' => 'Feed',
      'position' => 1,
      'display_options' => [
        'path' => 'cvt-feed.xml',
        'defaults' => ['style' => FALSE, 'row' => FALSE, 'fields' => FALSE],
        'style' => ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => 'feed_only']],
        'row' => ['type' => 'fields'],
        'fields' => $displays['default']['display_options']['fields'],
      ],
    ];
    $feed->set('display', $displays)->save();
    ViewTemplate::create(['id' => 'feed_only', 'label' => 'Feed only'])->save();
    $using = $choices->forEntity(ViewTemplate::load('feed_only'));
    self::assertSame(ViewFieldChoices::SOURCE_USING, $using['source']);
    self::assertSame(['cvt_feed_field'], \array_keys($using['options']));

    // A template nothing uses, and no entity at all, offer every View's fields.
    foreach ([ViewTemplate::load('unused'), NULL] as $entity) {
      $all = $choices->forEntity($entity);
      self::assertSame(ViewFieldChoices::SOURCE_ALL, $all['source']);
      self::assertArrayHasKey('cvt_other_field', $all['options']);
      self::assertArrayHasKey('cvt_mine_field', $all['options']);
    }
  }

}
