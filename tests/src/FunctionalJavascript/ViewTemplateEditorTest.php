<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\FunctionalJavascript;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the Canvas editor for a view template, in a real browser.
 *
 * Covers what kernel tests cannot reach: preview frames, the Page data select.
 */
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateEditorTest extends WebDriverTestBase {

  private const string KEY_ARROW_DOWN = "\u{E015}";
  private const string KEY_ENTER = "\u{E007}";
  private const string KEY_ESCAPE = "\u{E00C}";

  /**
   * The row menu's sizes in px: Canvas's menus (Radix Themes, size 2).
   */
  private const array MENU_SIZES = [
    'padding' => 8,
    'radius' => 8,
    'font' => 14,
    'itemHeight' => 32,
    'labelHeight' => 32,
    'itemPadding' => 12,
    'lineHeight' => 1,
    'lineMargin' => 8,
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_dev_mode',
    'canvas_view_template',
    'node',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $title) {
      Node::create(['type' => 'article', 'title' => "$title article", 'status' => 1])->save();
    }

    $placement = static function (string $local_id, string $uuid): array {
      $component = Component::load(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . $local_id);
      \assert($component !== NULL);
      return [
        'uuid' => $uuid,
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [],
      ];
    };
    ViewTemplate::create([
      'id' => 'editor_test',
      'label' => 'Editor test',
      'component_tree' => [
        $placement(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID, '11111111-1111-4111-8111-111111111111'),
        $placement(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID, '22222222-2222-4222-8222-222222222222'),
      ],
    ])->save();

    View::create([
      'id' => 'editor_test_view',
      'label' => 'Editor test view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'editor_test'],
            ],
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'order' => 'ASC',
                'plugin_id' => 'standard',
              ],
            ],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 2]],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_options' => ['path' => 'editor-test-list'],
        ],
      ],
    ])->save();

    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));
  }

  /**
   * The editor opens for a view template and offers the views to preview with.
   */
  public function testEditorOpensWithViewSelect(): void {
    $this->openEditor();
    $select = $this->assertSession()->waitForField('Preview with view', 30000);
    self::assertNotNull($select);
    self::assertSame('editor_test_view/page_1', $select->getValue());
    self::assertStringContainsString('(uses this template)', $select->find('css', 'option[value="editor_test_view/page_1"]')->getText());
    // No optgroup: a group label would be sent instead of the value.
    self::assertNull($select->find('css', 'optgroup'));
  }

  /**
   * The full-width preview shows the view's content, and its pager works.
   */
  public function testFullWidthPreviewShowsLiveContentAndPages(): void {
    $this->openEditor();
    self::assertNotNull($this->assertSession()->waitForField('Preview with view', 30000));
    $this->openFullWidthPreview();

    $this->assertFrameContains('Alpha article');
    $this->assertFrameContains('Bravo article');
    $this->assertPreviewDoesNotContain('Charlie article');

    $this->getSession()->evaluateScript("(function () { var link = document.querySelector('.pager__item--next a, a[rel=\"next\"]'); if (!link) { throw new Error('No next link'); } link.click(); })()");
    $this->assertFrameContains('Charlie article');
    $this->assertPreviewDoesNotContain('Alpha article');
    $this->getSession()->switchToIFrame();
  }

  /**
   * The chosen view is still chosen after opening the full-width preview.
   */
  public function testSelectionSurvivesOpeningThePreview(): void {
    $this->openEditor();
    $select = $this->assertSession()->waitForField('Preview with view', 30000);
    self::assertNotNull($select);
    $select->selectOption('none');
    // The choice is stored when the next preview request reaches the server.
    $stored = fn (): mixed => \Drupal::keyValueExpirable('tempstore.private.canvas_view_template')->get($this->loggedInUser->id() . ':preview:editor_test')?->data;
    self::assertTrue((bool) $this->getSession()->getPage()->waitFor(30, fn (): bool => $stored() === 'none'), 'The preview choice was never stored.');
    $this->openFullWidthPreview();
    $this->getSession()->switchToIFrame();
    $this->clickButton('Exit Preview');
    $select = $this->assertSession()->waitForField('Preview with view', 30000);
    self::assertNotNull($select);
    self::assertSame('none', $select->getValue());
  }

  /**
   * A repeater placed inside another is flagged in the editor's preview.
   */
  public function testNestedRepeaterIsFlagged(): void {
    $repeater = Component::load('canvas_view_template_repeater.view_repeater');
    self::assertNotNull($repeater);
    $outer = [
      'uuid' => '33333333-3333-4333-8333-333333333333',
      'component_id' => $repeater->id(),
      'component_version' => $repeater->getActiveVersion(),
      'inputs' => $repeater->getComponentSource()->getDefaultExplicitInput(),
    ];
    $inner = ['uuid' => '44444444-4444-4444-8444-444444444444', 'parent_uuid' => $outer['uuid'], 'slot' => 'item'] + $outer;
    ViewTemplate::create([
      'id' => 'nested_editor_test',
      'label' => 'Nested editor test',
      'component_tree' => [$outer, $inner],
    ])->save();

    $this->getSession()->resizeWindow(1600, 1000);
    $this->drupalGet('/canvas/editor/canvas_view_template/nested_editor_test');
    $this->enterFrame('iframe[data-canvas-iframe]', 'cvt-edit-preview');
    $this->assertFrameContains('cannot be placed inside another');
    $this->getSession()->switchToIFrame();

    $this->drupalGet('/canvas/editor/canvas_view_template/editor_test');
    $this->enterFrame('iframe[data-canvas-iframe]', 'cvt-edit-preview');
    // The warning would be rendered along with the placeholders.
    self::assertNotNull($this->assertSession()->waitForElement('css', '.cvt-marker--rows', 30000), 'The editor preview never rendered.');
    self::assertStringNotContainsString('cannot be placed inside another', $this->getSession()->getPage()->getText());
    $this->getSession()->switchToIFrame();
  }

  /**
   * The View templates panel shows which Views use each template.
   */
  public function testPanelShowsUsage(): void {
    ViewTemplate::create(['id' => 'unused_template', 'label' => 'Unused template'])->save();
    $this->openPanel();
    $rows = $this->panelRows();
    self::assertStringContainsString('Used by Editor test view: Page', $rows['Editor test']);
    self::assertStringContainsString('Not used', $rows['Unused template']);
    $this->getSession()->switchToIFrame();
  }

  /**
   * A template can be duplicated from its row menu.
   */
  public function testPanelDuplicatesTemplate(): void {
    $this->openPanel();
    $this->chooseFromRowMenu('Editor test', 'Duplicate');
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    self::assertSame('Copy of Editor test', $dialog->findField('Label')->getValue());
    self::assertSame('copy_of_editor_test', $dialog->findField('Machine name')->getValue());
    $dialog->findField('Label')->setValue('Second layout');
    self::assertSame('second_layout', $dialog->findField('Machine name')->getValue());
    $dialog->pressButton('Duplicate and edit');

    self::assertNotNull($this->assertSession()->waitForElement('css', 'iframe[data-canvas-iframe]', 30000));
    self::assertStringContainsString('/canvas/editor/canvas_view_template/second_layout', $this->getSession()->getCurrentUrl());
    $storage = \Drupal::entityTypeManager()->getStorage('canvas_view_template');
    $storage->resetCache();
    $copy = $storage->load('second_layout');
    self::assertNotNull($copy);
    self::assertSame('Second layout', $copy->label());
    self::assertCount(2, $copy->getComponentTree()->getValue());
    self::assertNotNull($storage->load('editor_test'));
  }

  /**
   * A template in use cannot be deleted from the panel, and is explained.
   */
  public function testPanelDoesNotDeleteTemplateInUse(): void {
    $this->openPanel();
    $this->spyOnPanelRequests();
    $this->chooseFromRowMenu('Editor test', 'Delete');
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    self::assertStringContainsString('Template is in use', $dialog->getText());
    self::assertStringContainsString('Editor test view: Page', $dialog->getText());
    self::assertNull($dialog->findButton('Delete'));
    $dialog->pressButton('OK');
    self::assertTrue($this->assertSession()->waitForElementRemoved('css', '.cvt-overlay', 10000));
    $this->getSession()->switchToIFrame('cvt-panel');
    $requests = $this->getSession()->evaluateScript('window.cvtRequests');
    self::assertContains('GET /canvas-view-template/usage/editor_test', $requests, 'The spy saw no usage request.');
    self::assertSame([], \preg_grep('/^DELETE /', $requests), 'The panel sent a DELETE.');
    $this->getSession()->switchToIFrame();

    $status = $this->getSession()->evaluateScript("(async function () { var token = await (await fetch('/session/token')).text(); var response = await fetch('/canvas/api/v0/config/canvas_view_template/editor_test', { method: 'DELETE', headers: { 'X-CSRF-Token': token } }); return response.status; })()");
    self::assertSame(403, $status);
    $storage = \Drupal::entityTypeManager()->getStorage('canvas_view_template');
    $storage->resetCache();
    self::assertNotNull($storage->load('editor_test'));
  }

  /**
   * An unused template is deleted from the panel after confirming.
   */
  public function testPanelDeletesUnusedTemplate(): void {
    ViewTemplate::create(['id' => 'unused_template', 'label' => 'Unused template'])->save();
    $this->openPanel();
    $this->chooseFromRowMenu('Unused template', 'Delete');
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    self::assertStringContainsString('Delete template', $dialog->getText());
    $dialog->pressButton('Delete');
    self::assertTrue($this->assertSession()->waitForElementRemoved('css', '.cvt-overlay', 10000));

    $this->getSession()->switchToIFrame('cvt-panel');
    self::assertTrue(
      (bool) $this->getSession()->getPage()->waitFor(10, fn (): bool => !\array_key_exists('Unused template', $this->panelRows())),
      'The deleted template is still listed.',
    );
    self::assertArrayHasKey('Editor test', $this->panelRows());
    $this->getSession()->switchToIFrame();
    $storage = \Drupal::entityTypeManager()->getStorage('canvas_view_template');
    $storage->resetCache();
    self::assertNull($storage->load('unused_template'));
    self::assertNotNull($storage->load('editor_test'));
  }

  /**
   * Focus goes back to the row's button when its menu or a dialog closes.
   */
  public function testPanelReturnsFocusToRowButton(): void {
    $this->openPanel();
    $dots = 'button.node__dots[aria-label="Actions for Editor test"]';
    $webdriver = $this->getSession()->getDriver()->getWebDriverSession();
    $press = static function (string $selector, string $key) use ($webdriver): void {
      $webdriver->element('css selector', $selector)->postValue(['text' => $key]);
    };

    // Escape from the menu.
    $this->getSession()->evaluateScript(\sprintf("document.querySelector('%s').focus();", \addslashes($dots)));
    $press($dots, self::KEY_ARROW_DOWN);
    $this->getSession()->switchToIFrame();
    self::assertNotNull($this->assertSession()->waitForElementVisible('css', '.cvt-menu', 10000));
    $press('.cvt-menu__item', self::KEY_ESCAPE);
    self::assertTrue($this->assertSession()->waitForElementRemoved('css', '.cvt-menu', 10000));
    $this->assertRowButtonFocused($dots);

    // Cancel the Duplicate dialog, opened from the keyboard.
    $press($dots, self::KEY_ARROW_DOWN);
    $this->getSession()->switchToIFrame();
    self::assertNotNull($this->assertSession()->waitForElementVisible('css', '.cvt-menu', 10000));
    $this->getSession()->wait(10000, "document.activeElement && document.activeElement.textContent === 'Edit'");
    $press('.cvt-menu__item', self::KEY_ARROW_DOWN);
    $press('.cvt-menu__item + .cvt-menu__separator + .cvt-menu__item', self::KEY_ENTER);
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    self::assertStringContainsString('Duplicate view template', $dialog->getText());
    $dialog->pressButton('Cancel');
    self::assertTrue($this->assertSession()->waitForElementRemoved('css', '.cvt-overlay', 10000));
    $this->assertRowButtonFocused($dots);
  }

  /**
   * A copy that fails validation shows the server's reason and stays open.
   */
  public function testPanelShowsWhyDuplicateWasRefused(): void {
    $rows = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID;
    // Two "View rows" is a draft Canvas only rejects when published.
    ViewTemplate::create([
      'id' => 'two_rows',
      'label' => 'Two rows',
      'component_tree' => [$this->treeItem($rows), $this->treeItem($rows)],
    ])->save();
    $this->openPanel();
    $this->chooseFromRowMenu('Two rows', 'Duplicate');
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    $dialog->pressButton('Duplicate and edit');

    $error = $this->waitForDialogError();
    self::assertStringContainsString('Could not duplicate the view template:', $error);
    self::assertStringContainsString('only one "View rows" placement', $error);
    // The dialog stays usable.
    self::assertTrue($this->getSession()->getPage()->find('css', '.cvt-dialog')->findButton('Duplicate and edit')->isVisible());
    self::assertNull(\Drupal::entityTypeManager()->getStorage('canvas_view_template')->load('copy_of_two_rows'));
  }

  /**
   * A machine name that is taken is explained, when creating and duplicating.
   */
  public function testPanelExplainsTakenMachineName(): void {
    $taken = 'A view template with the machine name "editor_test" already exists.';
    $this->openPanel();

    $this->chooseFromRowMenu('Editor test', 'Duplicate');
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    $dialog->findField('Machine name')->setValue('editor_test');
    $dialog->pressButton('Duplicate and edit');
    self::assertSame($taken, $this->waitForDialogError());
    self::assertSame('true', $dialog->findField('Machine name')->getAttribute('aria-invalid'));
    $dialog->pressButton('Cancel');
    self::assertTrue($this->assertSession()->waitForElementRemoved('css', '.cvt-overlay', 10000));

    // "Editor test" makes the machine name `editor_test`.
    $this->getSession()->switchToIFrame('cvt-panel');
    $this->getSession()->getPage()->find('css', '#new-button')->click();
    $this->getSession()->switchToIFrame();
    $dialog = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog', 10000);
    self::assertNotNull($dialog);
    $dialog->findField('Label')->setValue('Editor test');
    self::assertSame('editor_test', $dialog->findField('Machine name')->getValue());
    $dialog->pressButton('Create and edit');
    self::assertSame($taken, $this->waitForDialogError());
    self::assertSame('true', $dialog->findField('Machine name')->getAttribute('aria-invalid'));
    self::assertCount(1, \Drupal::entityTypeManager()->getStorage('canvas_view_template')->loadByProperties(['label' => 'Editor test']));
  }

  /**
   * Submitting an exposed filter in the full-width preview updates it.
   */
  public function testPreviewAppliesExposedFilter(): void {
    $this->createFilterFixture();
    $this->openFilterPreview();
    $this->assertFrameContains('Alpha article');

    $this->applyTitleFilter('Charlie');
    $this->assertFrameContains('Charlie article');
    self::assertTrue($this->waitForFrameText('Alpha article', FALSE), 'The unfiltered rows stayed.');
    $this->getSession()->switchToIFrame();
  }

  /**
   * Content first shown on a later page gets its styles from that page's load.
   */
  public function testPreviewPagerLoadsStylesFirstNeededOnLaterPage(): void {
    $this->createPagerFixture();
    $this->openEditor('pager_test');
    self::assertNotNull($this->assertSession()->waitForField('Preview with view', 30000));
    $this->openFullWidthPreview();
    // The first page shows only the pager; the repeater is for page 2. The
    // live block is hidden until the script has set up its click handling.
    self::assertNotNull($this->assertSession()->waitForElementVisible('css', '.cvt-live-preview .pager', 30000), 'The live preview never loaded.');
    $stylesheet = "document.querySelector('link[href*=\"field.css\"]')";
    self::assertFalse($this->getSession()->evaluateScript("$stylesheet !== null"), 'The field styles were already loaded.');
    $this->assertPreviewDoesNotContain('Charlie article');

    $this->getSession()->evaluateScript("document.querySelector('.cvt-live-preview .pager__item--next a, .cvt-live-preview a[rel=\"next\"]').click()");
    $this->assertFrameContains('Charlie article');
    self::assertTrue((bool) $this->getSession()->wait(30000, "$stylesheet !== null"), 'The styles for the later page never loaded.');
    $this->getSession()->switchToIFrame();
  }

  /**
   * A failed request shows a notice and keeps the content; a retry clears it.
   */
  public function testPreviewKeepsContentWhenRequestFails(): void {
    $this->createFilterFixture();
    $this->openFilterPreview();
    $this->assertFrameContains('Alpha article');

    // The preview calls the global `fetch`; a server error stands in.
    $this->getSession()->executeScript("window.cvtFetch = window.fetch; window.fetch = function () { return Promise.resolve(new Response('', { status: 500 })); }");
    $this->applyTitleFilter('Charlie');
    $notice = $this->assertSession()->waitForElementVisible('css', '.cvt-preview-error[role="alert"]', 30000);
    self::assertNotNull($notice, 'No error notice appeared.');
    self::assertStringContainsString('The preview could not load this view.', $notice->getText());
    self::assertStringContainsString('error 500', $notice->getText());
    self::assertStringContainsString('Alpha article', $this->getSession()->getPage()->getText());

    $this->getSession()->executeScript('window.fetch = window.cvtFetch');
    $this->applyTitleFilter('Charlie');
    $this->assertFrameContains('Charlie article');
    self::assertTrue((bool) $this->getSession()->wait(30000, "document.querySelector('.cvt-preview-error').hidden"), 'The notice stayed after a good load.');
    $this->getSession()->switchToIFrame();
  }

  /**
   * Asserts focus is on a row's button in the panel, from the editor page.
   */
  private function assertRowButtonFocused(string $selector): void {
    self::assertSame('cvt-panel', $this->getSession()->evaluateScript('document.activeElement && document.activeElement.name'));
    $this->getSession()->switchToIFrame('cvt-panel');
    self::assertTrue($this->getSession()->evaluateScript(\sprintf("document.activeElement === document.querySelector('%s')", \addslashes($selector))), 'Focus did not return to the row button.');
  }

  /**
   * Records the panel's requests in `window.cvtRequests`, as "METHOD path".
   */
  private function spyOnPanelRequests(): void {
    $this->getSession()->evaluateScript("(function () { window.cvtRequests = []; var original = window.fetch; window.fetch = function (url, init) { window.cvtRequests.push(((init && init.method) || 'GET') + ' ' + new URL(String(url), location.href).pathname); return original.apply(this, arguments); }; })()");
  }

  /**
   * Chooses an item from a row's menu, which lives outside the panel's frame.
   */
  private function chooseFromRowMenu(string $template_label, string $item): void {
    // The button is hover-only; a script can click it anyway.
    $this->getSession()->evaluateScript(\sprintf("document.querySelector('button.node__dots[aria-label=\"Actions for %s\"]').click();", $template_label));
    $button = $this->getSession()->evaluateScript(\sprintf("(function () { var box = document.querySelector('button.node__dots[aria-label=\"Actions for %s\"]').getBoundingClientRect(); return { left: box.left, bottom: box.bottom }; })()", $template_label));
    $this->getSession()->switchToIFrame();
    $menu = $this->assertSession()->waitForElementVisible('css', '.cvt-menu', 10000);
    self::assertNotNull($menu, 'The row menu never opened.');
    self::assertSame($template_label, $menu->find('css', '.cvt-menu__label')->getText());
    $frame = $this->getSession()->evaluateScript("(function () { var box = document.querySelector('iframe[name=\"cvt-panel\"]').getBoundingClientRect(); return { left: box.left, top: box.top }; })()");
    $placed = $this->getSession()->evaluateScript("(function () { var box = document.querySelector('.cvt-menu').getBoundingClientRect(); return { left: box.left, top: box.top }; })()");
    $sizes = $this->getSession()->evaluateScript("(function () { var menu = document.querySelector('.cvt-menu'); var item = menu.querySelector('.cvt-menu__item'); var label = menu.querySelector('.cvt-menu__label'); var line = menu.querySelector('.cvt-menu__separator'); var style = function (node, name) { return parseFloat(getComputedStyle(node)[name]); }; return { padding: style(menu, 'paddingLeft'), radius: style(menu, 'borderTopLeftRadius'), font: style(item, 'fontSize'), itemHeight: style(item, 'height'), labelHeight: style(label, 'height'), itemPadding: style(item, 'paddingLeft'), lineHeight: style(line, 'height'), lineMargin: style(line, 'marginTop') }; })()");
    foreach (self::MENU_SIZES as $name => $expected) {
      self::assertEqualsWithDelta($expected, $sizes[$name], 1, "The menu's $name differs from Canvas's.");
    }
    self::assertEqualsWithDelta($frame['left'] + $button['left'], $placed['left'], 12, 'The menu is not beside its button.');
    self::assertEqualsWithDelta($frame['top'] + $button['bottom'], $placed['top'], 12, 'The menu is not below its button.');
    $this->getSession()->evaluateScript(\sprintf("Array.from(document.querySelectorAll('.cvt-menu__item')).find(function (item) { return item.textContent === '%s'; }).click();", $item));
  }

  /**
   * Opens the View templates panel and switches into its frame.
   */
  private function openPanel(): void {
    $this->getSession()->resizeWindow(1600, 1000);
    $this->drupalGet('/canvas/app/view_templates');
    $this->enterFrame('iframe[src*="extension/index.html"]', 'cvt-panel');
    // Rows are drawn once the usage is known.
    self::assertNotNull($this->assertSession()->waitForElement('css', '.node .node__usage', 30000), 'The panel never listed the templates.');
  }

  /**
   * The panel's rows, by template label: the whole text of each.
   *
   * @return array<string, string>
   *   Row text keyed by template label.
   */
  private function panelRows(): array {
    $rows = [];
    foreach ($this->getSession()->getPage()->findAll('css', '.node') as $row) {
      $rows[$row->find('css', '.node__title')->getText()] = $row->getText();
    }
    return $rows;
  }

  /**
   * Waits for the open dialog's error and returns its text.
   */
  private function waitForDialogError(): string {
    $error = $this->assertSession()->waitForElementVisible('css', '.cvt-dialog .cvt-error', 10000);
    self::assertNotNull($error, 'The dialog showed no error.');
    return $error->getText();
  }

  /**
   * Builds an item for a component's tree, with its default inputs.
   *
   * @param string $component_id
   *   The component's id.
   * @param string|null $parent_uuid
   *   The parent item's uuid, for an item inside a slot.
   * @param string|null $slot
   *   The parent's slot.
   * @param array<string, mixed>|null $inputs
   *   Inputs replacing the defaults.
   *
   * @return array<string, mixed>
   *   The tree item.
   */
  private function treeItem(string $component_id, ?string $parent_uuid = NULL, ?string $slot = NULL, ?array $inputs = NULL): array {
    $component = Component::load($component_id);
    self::assertNotNull($component, $component_id);
    $item = [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $inputs ?? $component->getComponentSource()->getDefaultExplicitInput(),
    ];
    if ($parent_uuid !== NULL) {
      $item['parent_uuid'] = $parent_uuid;
      $item['slot'] = $slot;
    }
    return $item;
  }

  /**
   * Creates a page-display view of the articles, shown through a template.
   *
   * @param string $id
   *   The view id, also its page path.
   * @param string $template
   *   The view template id.
   * @param array<string, mixed> $options
   *   Display options added to the defaults, such as `filters`.
   */
  private function createListView(string $id, string $template, array $options = []): void {
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => $options + [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => $template],
            ],
            'row' => ['type' => 'fields'],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'label' => 'Title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'order' => 'ASC',
                'plugin_id' => 'standard',
              ],
            ],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 2]],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_options' => ['path' => $id],
        ],
      ],
    ])->save();
  }

  /**
   * Creates a view with a "Title" exposed filter, and its template.
   */
  private function createFilterFixture(): void {
    $prefix = ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.';
    ViewTemplate::create([
      'id' => 'filter_test',
      'label' => 'Filter test',
      'component_tree' => [
        $this->treeItem($prefix . ViewTemplatePlaceholder::VIEW_EXPOSED_FILTERS_LOCAL_ID),
        $this->treeItem($prefix . ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
      ],
    ])->save();
    $this->createListView('filter_test_view', 'filter_test', [
      'filters' => [
        'title' => [
          'id' => 'title',
          'table' => 'node_field_data',
          'field' => 'title',
          'entity_type' => 'node',
          'entity_field' => 'title',
          'plugin_id' => 'string',
          'operator' => 'contains',
          'exposed' => TRUE,
          'expose' => ['identifier' => 'title', 'label' => 'Title', 'operator_id' => 'title_op'],
        ],
      ],
    ]);
  }

  /**
   * Creates a view whose field only shows on page 2, and its template.
   *
   * The field is the first thing to need `field.css`, so it can only arrive
   * with the second page's load.
   */
  private function createPagerFixture(): void {
    $repeater_id = 'canvas_view_template_repeater.view_repeater';
    $repeater = $this->treeItem($repeater_id);
    $repeater['inputs'] = [
      'page_condition_mode' => 'only',
      'page_condition_page' => 2,
    ] + $repeater['inputs'];
    ViewTemplate::create([
      'id' => 'pager_test',
      'label' => 'Pager test',
      'component_tree' => [
        $repeater,
        $this->treeItem(
          'canvas_view_template_field.view_field',
          $repeater['uuid'],
          'item',
          ['field' => 'title', 'element' => 'div', 'label' => 'inline'],
        ),
        $this->treeItem(ViewTemplatePlaceholder::SOURCE_PLUGIN_ID . '.' . ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
      ],
    ])->save();
    $this->createListView('pager_test_view', 'pager_test');
  }

  /**
   * Opens the filter fixture's full-width preview and switches into it.
   */
  private function openFilterPreview(): void {
    $this->openEditor('filter_test');
    self::assertNotNull($this->assertSession()->waitForField('Preview with view', 30000));
    $this->openFullWidthPreview();
  }

  /**
   * Enters a value in the preview's "Title" filter and applies it.
   */
  private function applyTitleFilter(string $value): void {
    $page = $this->getSession()->getPage();
    $field = $this->assertSession()->waitForElementVisible('css', '.cvt-live-preview input[name="title"]', 30000);
    self::assertNotNull($field, 'The exposed filter never showed.');
    $field->setValue($value);
    $page->pressButton('Apply');
  }

  /**
   * Waits until the frame's text does (or does not) contain a string.
   */
  private function waitForFrameText(string $text, bool $present): bool {
    return (bool) $this->getSession()->getPage()->waitFor(30, fn (): bool => \str_contains($this->getSession()->getPage()->getText(), $text) === $present);
  }

  /**
   * Opens the editor for a view template (the test template by default).
   */
  private function openEditor(string $template = 'editor_test'): void {
    $this->getSession()->resizeWindow(1600, 1000);
    $this->drupalGet('/canvas/editor/canvas_view_template/' . $template);
    self::assertNotNull($this->assertSession()->waitForElement('css', 'iframe[data-canvas-iframe]', 30000), 'The editor never loaded.');
  }

  /**
   * Clicks a button in the editor by its visible name.
   */
  private function clickButton(string $name): void {
    $button = $this->assertSession()->waitForElement('xpath', \sprintf('//button[normalize-space(.)="%1$s" or @aria-label="%1$s"]', $name), 30000);
    self::assertNotNull($button, "Button \"$name\" not found.");
    $button->click();
  }

  /**
   * Opens the full-width preview and switches into its frame.
   */
  private function openFullWidthPreview(): void {
    $this->clickButton('Preview');
    // Canvas's full-width frame: titled, and without the editor's marker.
    $selector = 'iframe[title="Page preview"]:not([data-canvas-iframe])';
    $this->enterFrame($selector, 'cvt-full-preview');
  }

  /**
   * Waits for an iframe, names it, and switches into it.
   */
  private function enterFrame(string $selector, string $name): void {
    $frame = $this->assertSession()->waitForElement('css', $selector, 30000);
    self::assertNotNull($frame, "No frame matches $selector.");
    $this->getSession()->evaluateScript(\sprintf("document.querySelector('%s').setAttribute('name', '%s');", \addslashes($selector), $name));
    $this->getSession()->switchToIFrame($name);
  }

  /**
   * Waits for text in the frame the session is in.
   */
  private function assertFrameContains(string $text): void {
    self::assertTrue(
      (bool) $this->getSession()->getPage()->waitFor(30, fn () => \str_contains($this->getSession()->getPage()->getText(), $text)),
      "The frame never showed \"$text\".",
    );
  }

  /**
   * Checks text is not in the full-width preview frame (after it settled).
   */
  private function assertPreviewDoesNotContain(string $text): void {
    self::assertStringNotContainsString($text, $this->getSession()->getPage()->getText());
  }

}
