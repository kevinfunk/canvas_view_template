<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Controller\ViewTemplateDuplicateController;
use Drupal\canvas_view_template\Controller\ViewTemplateUsageController;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Entity\ViewTemplateAccessControlHandler;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the server side of the View templates panel.
 */
#[CoversClass(ViewTemplateDuplicateController::class)]
#[CoversClass(ViewTemplateUsageController::class)]
#[CoversClass(ViewTemplateUsage::class)]
#[CoversClass(ViewTemplateAccessControlHandler::class)]
#[Group('canvas_view_template')]
final class ViewTemplatePanelBackendTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    // Not uid 1, which would have every permission.
    $this->setUpCurrentUser(['uid' => 2], ['administer view template']);

    $tree = [$this->item(ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID)];
    ViewTemplate::create(['id' => 'used', 'label' => 'Used', 'component_tree' => $tree])->save();
    ViewTemplate::create(['id' => 'other', 'label' => 'Other'])->save();
    ViewTemplate::create(['id' => 'unused', 'label' => 'Unused'])->save();
    $this->createView('first_view', 'First view', ['page_1' => 'used', 'page_2' => 'other']);
    $this->createView('second_view', 'Second view', ['page_1' => 'used']);
  }

  /**
   * A copy is made from the unpublished draft, not the published tree.
   */
  public function testDuplicateCopiesDraft(): void {
    $template = ViewTemplate::load('used');
    $published = $template->getComponentTree()->getValue();
    $draft_tree = [$this->item(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)];
    $this->saveDraft($template, $draft_tree);

    $response = $this->duplicate($template, 'used_copy');
    self::assertSame(201, $response->getStatusCode());
    $copy = ViewTemplate::load('used_copy');
    self::assertNotNull($copy);
    self::assertSame(\array_column($draft_tree, 'uuid'), \array_column($copy->getComponentTree()->getValue(), 'uuid'));
    self::assertSame(\array_column($published, 'uuid'), \array_column(ViewTemplate::load('used')->getComponentTree()->getValue(), 'uuid'));
  }

  /**
   * An invalid draft is reported with Canvas's error shape and not copied.
   */
  public function testDuplicateOfInvalidDraftIsRejected(): void {
    $template = ViewTemplate::load('used');
    $rows = ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;
    $this->saveDraft($template, [$this->item($rows), $this->item($rows)]);

    $response = $this->duplicate($template, 'invalid_copy');
    self::assertSame(422, $response->getStatusCode());
    $details = \array_column(\json_decode((string) $response->getContent(), TRUE)['errors'], 'detail');
    self::assertContains('A view template must contain only one "View rows" placement, but found 2.', $details);
    self::assertNull(ViewTemplate::load('invalid_copy'));
  }

  /**
   * Only users who may manage templates learn which Views use one.
   */
  public function testDeleteLockReasonNeedsAdminPermission(): void {
    $views_list_tag = 'config:view_list';
    $admin = $this->createUser([ViewTemplate::ADMIN_PERMISSION]);
    $visitor = $this->createUser(['access content']);
    $used = ViewTemplate::load('used');
    $unused = ViewTemplate::load('unused');

    $result = $used->access('delete', $visitor, TRUE);
    self::assertFalse($result->isAllowed());
    self::assertStringNotContainsString('First view', (string) $result->getReason());

    $result = $used->access('delete', $admin, TRUE);
    self::assertTrue($result->isForbidden());
    self::assertStringContainsString('First view: page_1, Second view: page_1', (string) $result->getReason());
    self::assertContains($views_list_tag, $result->getCacheTags());

    $result = $unused->access('delete', $admin, TRUE);
    self::assertTrue($result->isAllowed());
    self::assertContains($views_list_tag, $result->getCacheTags());
  }

  /**
   * Usage of all templates in one pass matches asking for each one.
   */
  public function testUsageOfAllTemplatesMatchesEach(): void {
    $usage = $this->container->get(ViewTemplateUsage::class);
    $all = $usage->displaysByTemplate();
    self::assertEqualsCanonicalizing(['other', 'used'], \array_keys($all));
    foreach (['used', 'other', 'unused'] as $id) {
      self::assertSame($all[$id] ?? [], $usage->displaysUsing($id), $id);
    }
    self::assertSame(['First view: page_1', 'Second view: page_1'], \array_column($all['used'], 'label'));

    $data = \json_decode((string) ViewTemplateUsageController::create($this->container)->all()->getContent(), TRUE);
    foreach (['used', 'other', 'unused'] as $id) {
      self::assertSame($usage->displaysUsing($id), $data['templates'][$id], $id);
    }
  }

  /**
   * A View changed earlier in the same request is counted as it is now.
   */
  public function testUsageIsNotStale(): void {
    $usage = $this->container->get(ViewTemplateUsage::class);
    self::assertCount(2, $usage->displaysUsing('used'));
    self::assertSame([], $usage->displaysUsing('unused'));

    $view = View::load('second_view');
    $displays = $view->get('display');
    $displays['page_1']['display_options']['style']['options'][ViewTemplateStyle::OPTION_KEY] = 'unused';
    $view->set('display', $displays)->save();

    self::assertSame(['First view: page_1'], \array_column($usage->displaysUsing('used'), 'label'));
    self::assertSame(['Second view: page_1'], \array_column($usage->displaysUsing('unused'), 'label'));

    View::load('first_view')->delete();
    self::assertSame([], $usage->displaysUsing('used'));
    self::assertSame([], $usage->displaysUsing('other'));
  }

  /**
   * Builds a tree item for one of this module's placeholder components.
   */
  private function item(string $local_id): array {
    $component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . $local_id);
    self::assertNotNull($component);
    return [
      'uuid' => $this->container->get('uuid')->generate(),
      'component_id' => $component->id(),
      'component_version' => $component->getActiveVersion(),
      'inputs' => [],
    ];
  }

  /**
   * Saves an unpublished draft of a template, as the editor does.
   */
  private function saveDraft(ViewTemplate $template, array $tree): void {
    $draft = ViewTemplate::load((string) $template->id());
    $draft->setComponentTree($tree);
    $manager = $this->container->get(AutoSaveManager::class);
    $manager->saveEntity($draft);
    self::assertFalse($manager->getAutoSaveEntity($template)->isEmpty());
  }

  /**
   * Asks the duplicate controller for a copy.
   */
  private function duplicate(ViewTemplate $template, string $id): mixed {
    $request = Request::create('/', 'POST', [], [], [], [], (string) \json_encode(['id' => $id, 'label' => 'Copy']));
    return ViewTemplateDuplicateController::create($this->container)->duplicate($template, $request);
  }

  /**
   * Creates a View whose page displays use the given templates.
   *
   * @param string $id
   *   The View id.
   * @param string $label
   *   The View label.
   * @param array<string, string> $templates
   *   Template ids keyed by page display id.
   */
  private function createView(string $id, string $label, array $templates): void {
    $displays = ['default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Default']];
    $n = 0;
    foreach ($templates as $display_id => $template_id) {
      $displays[$display_id] = [
        'display_plugin' => 'page',
        'id' => $display_id,
        'display_title' => $display_id,
        'display_options' => [
          'path' => $id . '-' . ++$n,
          'defaults' => ['style' => FALSE, 'row' => FALSE],
          'style' => [
            'type' => ViewTemplateStyle::PLUGIN_ID,
            'options' => [ViewTemplateStyle::OPTION_KEY => $template_id],
          ],
          'row' => ['type' => 'fields'],
        ],
      ];
    }
    View::create(['id' => $id, 'label' => $label, 'base_table' => 'node_field_data', 'display' => $displays])->save();
  }

}
