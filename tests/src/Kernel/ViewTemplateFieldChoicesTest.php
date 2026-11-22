<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\Preview\ViewTemplatePreviewSelection;
use Drupal\canvas_view_template\ViewFieldChoices;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
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
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    // Without these Views has no data for the node table, and every handler is
    // a broken one with no admin label.
    'node',
    'views',
  ];

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

  /**
   * Choices are sorted by label, numbers by value, ignoring case.
   */
  public function testChoicesAreSortedNaturallyIgnoringCase(): void {
    $this->createView('cvt_z', TRUE, ['cvt_zebra' => ['id' => 'cvt_zebra', 'label' => 'Zebra']]);
    $this->createView('cvt_y', TRUE, [
      'cvt_ten' => ['id' => 'cvt_ten', 'label' => 'Item 10'],
      'cvt_nine' => ['id' => 'cvt_nine', 'label' => 'item 9'],
    ]);
    $this->createView('cvt_x', TRUE, ['cvt_apple' => ['id' => 'cvt_apple', 'label' => 'apple']]);

    $options = $this->container->get(ViewFieldChoices::class)->forEntity(NULL)['options'];
    $ours = \array_filter($options, static fn (string $key): bool => \str_starts_with($key, 'cvt_'), ARRAY_FILTER_USE_KEY);
    // The Views are listed by name, so unsorted this would be apple, ten, nine.
    self::assertSame(['cvt_apple', 'cvt_nine', 'cvt_ten', 'cvt_zebra'], \array_keys($ours));
  }

  /**
   * A disabled View offers no fields, even to someone who may edit Views.
   */
  public function testDisabledViewsAreNotOfferedToAdmins(): void {
    $this->createView('cvt_on', TRUE, ['cvt_on_field' => ['id' => 'cvt_on_field', 'label' => 'On']]);
    $this->createView('cvt_off', FALSE, ['cvt_off_field' => ['id' => 'cvt_off_field', 'label' => 'Off']]);
    $admin = $this->createMock(AccountInterface::class);
    $admin->method('hasPermission')->willReturn(TRUE);
    $choices = new ViewFieldChoices(
      $this->container->get(ViewTemplatePreviewSelection::class),
      $this->container->get(ViewTemplateUsage::class),
      $this->container->get('config.factory'),
      $admin,
    );

    $options = $choices->forEntity(NULL)['options'];
    self::assertArrayHasKey('cvt_on_field', $options);
    self::assertArrayNotHasKey('cvt_off_field', $options);
  }

  /**
   * Displays using the template: the first names a field, restricted ones hide.
   *
   * A feed cannot be previewed, so a template used only by feeds takes its
   * choices from every display using it.
   */
  public function testDisplaysUsingTheTemplateNameFieldsInOrderAndRespectAccess(): void {
    // The real node title handler, so the admin label is the one shown.
    $field = static fn (string $id, string $label): array => [
      $id => [
        'id' => $id,
        'label' => '',
        'admin_label' => $label,
        'plugin_id' => 'field',
        'entity_type' => 'node',
        'entity_field' => 'title',
      ],
    ];
    $this->createView('cvt_a', TRUE, $field('cvt_shared', 'First'));
    $this->createView('cvt_b', TRUE, $field('cvt_shared', 'Second') + $field('cvt_b_only', 'B only'));
    $this->createView('cvt_locked', TRUE, $field('cvt_locked_field', 'Locked'));
    ViewTemplate::create(['id' => 'shared_feed', 'label' => 'Shared feed'])->save();
    $this->addFeedDisplay('cvt_a', 'shared_feed');
    $this->addFeedDisplay('cvt_b', 'shared_feed');
    $this->addFeedDisplay('cvt_locked', 'shared_feed', [
      'defaults' => ['access' => FALSE],
      'access' => ['type' => 'perm', 'options' => ['perm' => 'administer nodes']],
    ]);

    $using = $this->container->get(ViewFieldChoices::class)->forEntity(ViewTemplate::load('shared_feed'));
    self::assertSame(ViewFieldChoices::SOURCE_USING, $using['source']);
    // The display listed first (View "Cvt_a") names the shared field, and the
    // restricted display's field is left out.
    self::assertSame(['cvt_b_only' => 'B only', 'cvt_shared' => 'First'], $using['options']);
  }

  /**
   * Adds a feed display that uses a template to a View.
   *
   * @param string $view_id
   *   The View.
   * @param string $template_id
   *   The template the display uses.
   * @param array<string, mixed> $extra
   *   More display options; `defaults` is merged with the style defaults.
   */
  private function addFeedDisplay(string $view_id, string $template_id, array $extra = []): void {
    $view = View::load($view_id);
    $displays = $view->get('display');
    $options = [
      'path' => $view_id . '.xml',
      'defaults' => ['style' => FALSE, 'row' => FALSE, 'fields' => FALSE] + ($extra['defaults'] ?? []),
      'style' => ['type' => ViewTemplateStyle::PLUGIN_ID, 'options' => [ViewTemplateStyle::OPTION_KEY => $template_id]],
      'row' => ['type' => 'fields'],
      'fields' => $displays['default']['display_options']['fields'],
    ] + $extra;
    $displays['feed_1'] = [
      'display_plugin' => 'feed',
      'id' => 'feed_1',
      'display_title' => 'Feed',
      'position' => 1,
      'display_options' => $options,
    ];
    $view->set('display', $displays)->save();
  }

}
