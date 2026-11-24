<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the server side of the View templates panel.
 */
#[CoversClass(ViewTemplateDuplicateController::class)]
#[CoversClass(ViewTemplateUsageController::class)]
#[CoversClass(ViewTemplateUsage::class)]
#[CoversClass(ViewTemplateAccessControlHandler::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
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
    // Defines the "administer views" permission and the edit routes.
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    // Not uid 1, which would have every permission.
    $this->setUpCurrentUser(['uid' => 2], ['administer view templates', 'administer views']);

    $tree = [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ITEM_LOCAL_ID)];
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
    $draft_tree = [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)];
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
    $this->saveDraft($template, [$this->placeholderItem($rows), $this->placeholderItem($rows)]);

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
   * A copy takes the description from the same place as the tree: the draft.
   */
  public function testDuplicateTakesDescriptionFromDraft(): void {
    $template = ViewTemplate::load('used');
    $template->set('description', 'Published text')->save();
    $this->saveDraft($template, [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)], 'Draft text');

    self::assertSame(201, $this->duplicate($template, 'described_copy')->getStatusCode());
    self::assertSame('Draft text', ViewTemplate::load('described_copy')->get('description'));
  }

  /**
   * Copying needs the right to create templates.
   */
  public function testDuplicateNeedsCreateAccess(): void {
    $this->setCurrentUser($this->createUser(['access content']));
    $response = $this->duplicate(ViewTemplate::load('used'), 'forbidden_copy');
    self::assertSame(403, $response->getStatusCode());
    self::assertNull(ViewTemplate::load('forbidden_copy'));
  }

  /**
   * Names and edit links of Views an account cannot reach stay out of usage.
   */
  public function testUsageHidesViewsTheAccountCannotAccess(): void {
    $view = View::load('second_view');
    $displays = $view->get('display');
    $displays['page_1']['display_options']['defaults']['access'] = FALSE;
    $displays['page_1']['display_options']['access'] = ['type' => 'perm', 'options' => ['perm' => 'administer nodes']];
    $view->set('display', $displays)->save();
    $usage = $this->container->get(ViewTemplateUsage::class);

    // With "administer views" everything is listed, with its edit link.
    $admin = $this->createUser(['administer views']);
    $entries = $usage->visibleTo($usage->displaysUsing('used'), $admin);
    self::assertSame(['First view: page_1', 'Second view: page_1'], \array_column($entries, 'label'));
    self::assertNotSame('', $entries[0]['url']);

    // Without it, the restricted View is dropped and no link is given.
    $editor = $this->createUser([ViewTemplate::ADMIN_PERMISSION]);
    $entries = $usage->visibleTo($usage->displaysUsing('used'), $editor);
    self::assertSame(['First view: page_1'], \array_column($entries, 'label'));
    self::assertSame('', $entries[0]['url']);

    $this->setCurrentUser($editor);
    $data = \json_decode((string) ViewTemplateUsageController::create($this->container)->all()->getContent(), TRUE);
    self::assertSame(['First view: page_1'], \array_column($data['templates']['used'], 'label'));

    // The delete lock still holds, and does not name the hidden View.
    $reason = (string) ViewTemplate::load('used')->access('delete', $editor, TRUE)->getReason();
    self::assertStringContainsString('First view: page_1', $reason);
    self::assertStringNotContainsString('Second view', $reason);
    self::assertStringContainsString('1 more you cannot access', $reason);
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
   * A copy keeps the description and tree, and the new name is checked.
   */
  public function testDuplicateChecksTheNewName(): void {
    $original = ViewTemplate::load('used');
    $original->set('description', 'The original')->save();
    $controller = ViewTemplateDuplicateController::create($this->container);
    $send = static fn (array $body): Request => Request::create('/', 'POST', [], [], [], [], (string) \json_encode($body));

    $response = $controller->duplicate($original, $send(['id' => 'used_copy', 'label' => ' Used copy ']));
    self::assertSame(201, $response->getStatusCode());
    self::assertSame(['id' => 'used_copy', 'label' => 'Used copy'], \json_decode((string) $response->getContent(), TRUE));
    $copy = ViewTemplate::load('used_copy');
    self::assertNotNull($copy);
    self::assertSame('Used copy', $copy->label());
    self::assertSame('The original', $copy->get('description'));
    self::assertEquals($original->getComponentTree()->getValue(), $copy->getComponentTree()->getValue());
    self::assertNotNull(ViewTemplate::load('used'));
    // The copy starts unused: Views keep the original until switched.
    self::assertSame([], $this->container->get(ViewTemplateUsage::class)->displaysUsing('used_copy'));

    $response = $controller->duplicate($original, $send(['id' => 'used_copy', 'label' => 'Again']));
    self::assertSame(409, $response->getStatusCode());
    self::assertStringContainsString('already exists', (string) $response->getContent());

    foreach ([['id' => 'Bad Name', 'label' => 'X'], ['id' => '1abc', 'label' => 'X'], ['id' => 'fine', 'label' => ' ']] as $body) {
      self::assertSame(422, $controller->duplicate($original, $send($body))->getStatusCode(), (string) \json_encode($body));
    }
    self::assertNull(ViewTemplate::load('fine'));
  }

  /**
   * The usage routes answer privately, so a stale list is never cached.
   */
  public function testUsageRoutesAreNotCached(): void {
    $controller = ViewTemplateUsageController::create($this->container);

    $response = $controller->usage(ViewTemplate::load('used'));
    $data = \json_decode((string) $response->getContent(), TRUE);
    self::assertSame(['First view: page_1', 'Second view: page_1'], \array_column($data['displays'], 'label'));
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    self::assertStringContainsString('no-store', (string) $controller->all()->headers->get('Cache-Control'));
  }

  /**
   * The delete lock lifts once no display uses the template.
   */
  public function testDeleteLockLiftsWhenNoDisplayUsesTheTemplate(): void {
    $admin = $this->createUser([ViewTemplate::ADMIN_PERMISSION]);
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('canvas_view_template');
    $used = ViewTemplate::load('used');
    self::assertTrue($used->access('delete', $admin, TRUE)->isForbidden());
    // Editing is never locked.
    self::assertTrue($used->access('update', $admin));

    View::load('first_view')->delete();
    View::load('second_view')->delete();
    $handler->resetCache();
    self::assertTrue(ViewTemplate::load('used')->access('delete', $admin));
  }

  /**
   * Saves an unpublished draft of a template, as the editor does.
   */
  private function saveDraft(ViewTemplate $template, array $tree, ?string $description = NULL): void {
    $draft = ViewTemplate::load((string) $template->id());
    $draft->setComponentTree($tree);
    if ($description !== NULL) {
      $draft->set('description', $description);
    }
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
