<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateField;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateRepeater;
use Drupal\canvas_view_template\Plugin\views\style\ViewTemplateStyle;
use Drupal\Core\Session\AccountInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests that a View shown through a template never gets another's cache entry.
 *
 * The View is rendered through the real render cache as different users, on
 * different pages and with different filter values. Each variant must show its
 * own rows however the cache was warmed. A missing cache context would show up
 * here as the wrong rows.
 */
#[CoversClass(ViewTemplateThemeHooks::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewTemplateFrontEndCacheTest extends ViewTemplateKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'content_translation',
    'language',
    'node',
    'views',
  ];

  /**
   * A user who sees published content.
   */
  private AccountInterface $reader;

  /**
   * A user who also sees unpublished content.
   */
  private AccountInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->installEntitySchema('user');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    // Node IDs follow this order, and the View sorts by them: the unpublished
    // node is second, so it changes what both pages show for an editor.
    foreach (['Alpha' => TRUE, 'Secret' => FALSE, 'Bravo' => TRUE, 'Charlie' => TRUE, 'Delta' => TRUE] as $title => $published) {
      Node::create(['type' => 'article', 'title' => $title, 'status' => $published])->save();
    }
    // Explicit IDs: the first user a kernel test creates would be uid 1, who
    // passes every access check.
    $this->reader = $this->createUser(['access content'], NULL, FALSE, ['uid' => 2]);
    $this->editor = $this->createUser(['access content', 'bypass node access'], NULL, FALSE, ['uid' => 3]);

    ViewTemplate::create([
      'id' => 'cache_template',
      'label' => 'Cache template',
      'component_tree' => [
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID),
        $this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID),
      ],
    ])->save();
    View::create([
      'id' => 'cache_view',
      'label' => 'Cache view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'style' => [
              'type' => ViewTemplateStyle::PLUGIN_ID,
              'options' => [ViewTemplateStyle::OPTION_KEY => 'cache_template'],
            ],
            'row' => ['type' => 'fields'],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 2]],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
                'label' => '',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
            ],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'entity_type' => 'node',
                'entity_field' => 'nid',
                'plugin_id' => 'standard',
                'order' => 'ASC',
              ],
            ],
            'filters' => [
              // What the standard node Views add: unpublished nodes are for
              // users who may see them.
              'status_extra' => [
                'id' => 'status_extra',
                'table' => 'node_field_data',
                'field' => 'status_extra',
                'entity_type' => 'node',
                'plugin_id' => 'node_status',
                'group' => 1,
              ],
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'string',
                'operator' => 'contains',
                'exposed' => TRUE,
                'expose' => ['identifier' => 'search', 'label' => 'Title', 'remember' => FALSE],
              ],
            ],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Renders the View through the render cache as an account, for a request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Who is viewing.
   * @param array<string, mixed> $query
   *   The query string: `page` and the exposed filter `search`.
   * @param string $view_id
   *   The View to render.
   *
   * @return string
   *   The HTML.
   */
  private function renderAs(AccountInterface $account, array $query = [], string $view_id = 'cache_view'): string {
    $stack = $this->container->get('request_stack');
    // The exposed filter's form needs a session, as a real request has.
    $request = Request::create('/cache-view', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($request);
    $this->setCurrentUser($account);
    try {
      $view = Views::getView($view_id);
      self::assertNotNull($view);
      $build = $view->buildRenderable('default');
      self::assertNotEmpty($build['#cache']['keys'] ?? [], 'The View is not render cached, so this test proves nothing.');
      return (string) $this->renderer->renderRoot($build);
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Every variant shows its own rows, whatever the cache holds.
   */
  public function testEachUserPageAndFilterGetsItsOwnOutput(): void {
    // Who views, the query string, the titles that must and must not show.
    $variants = [
      'reader, page 1' => [$this->reader, [], ['Alpha', 'Bravo'], ['Secret', 'Charlie']],
      'reader, page 2' => [$this->reader, ['page' => 1], ['Charlie', 'Delta'], ['Alpha', 'Secret']],
      'editor, page 1' => [$this->editor, [], ['Alpha', 'Secret'], ['Bravo', 'Charlie']],
      'editor, page 2' => [$this->editor, ['page' => 1], ['Bravo', 'Charlie'], ['Alpha', 'Secret']],
      'reader, filtered' => [$this->reader, ['search' => 'char'], ['Charlie'], ['Alpha', 'Bravo', 'Delta']],
      'editor, filtered' => [$this->editor, ['search' => 'cret'], ['Secret'], ['Alpha', 'Charlie']],
    ];
    // Forward, then backward: the cache is warm by the second pass, and a
    // variant that was only correct because it ran first would fail here.
    foreach ([$variants, \array_reverse($variants, TRUE), $variants] as $pass => $order) {
      foreach ($order as $name => [$account, $query, $shown, $hidden]) {
        $html = $this->renderAs($account, $query);
        foreach ($shown as $title) {
          self::assertStringContainsString($title, $html, \sprintf('%s shows %s (pass %d).', $name, $title, $pass));
        }
        foreach ($hidden as $title) {
          self::assertStringNotContainsString($title, $html, \sprintf('%s must not show %s (pass %d).', $name, $title, $pass));
        }
      }
    }
  }

  /**
   * Switches the content and interface language for what renders next.
   */
  private function useLanguage(string $langcode): void {
    // In a kernel test the negotiator is not given the current user, and
    // without one it skips negotiation and uses the default language.
    $this->container->get('language_negotiator')->setCurrentUser($this->container->get('current_user'));
    $this->config('language.negotiation')->set('selected_langcode', $langcode)->save();
    $this->container->get('language_manager')->reset();
  }

  /**
   * Each language gets its own rows and links, whatever the cache holds.
   */
  public function testEachLanguageGetsItsOwnOutput(): void {
    // Each node also exists in French, and has a page in each language.
    foreach (Node::loadMultiple() as $node) {
      $title = (string) $node->label();
      $node->addTranslation('fr', ['title' => $title . ' FR'])->save();
      foreach (['en' => '', 'fr' => '-fr'] as $langcode => $suffix) {
        PathAlias::create([
          'path' => '/node/' . $node->id(),
          'alias' => '/' . \strtolower($title) . $suffix,
          'langcode' => $langcode,
        ])->save();
      }
    }
    $this->config('language.types')
      ->set('configurable', ['language_interface', 'language_content'])
      ->set('negotiation.language_content.enabled', ['language-selected' => 0])
      ->set('negotiation.language_interface.enabled', ['language-selected' => 0])
      ->save();
    $repeater = $this->treeItem(ViewTemplateRepeater::SOURCE_PLUGIN_ID . '.' . ViewTemplateRepeater::LOCAL_ID);
    $link = $this->treeItem(
      ViewTemplateItemLink::SOURCE_PLUGIN_ID . '.' . ViewTemplateItemLink::LOCAL_ID,
      $repeater['uuid'],
      ViewTemplateRepeater::SLOT_NAME,
    );
    $inputs = [
      ViewTemplateField::INPUT_FIELD => 'title',
      ViewTemplateField::INPUT_ELEMENT => 'span',
      ViewTemplateField::INPUT_LABEL => 'hidden',
    ];
    $title = $this->treeItem(
      ViewTemplateField::SOURCE_PLUGIN_ID . '.' . ViewTemplateField::LOCAL_ID,
      $link['uuid'],
      ViewTemplateItemLink::SLOT_NAME,
      $inputs,
    );
    ViewTemplate::create([
      'id' => 'language_template',
      'label' => 'Language template',
      'component_tree' => [$repeater, $link, $title],
    ])->save();
    $display_options = [
      'style' => [
        'type' => ViewTemplateStyle::PLUGIN_ID,
        'options' => [ViewTemplateStyle::OPTION_KEY => 'language_template'],
      ],
      'row' => ['type' => 'fields'],
      'pager' => ['type' => 'none', 'options' => []],
      'rendering_language' => '***LANGUAGE_language_content***',
      // One row per node, in the content language: without this, Views lists
      // every translation as a row.
      'filters' => [
        'langcode' => [
          'id' => 'langcode',
          'table' => 'node_field_data',
          'field' => 'langcode',
          'entity_type' => 'node',
          'entity_field' => 'langcode',
          'plugin_id' => 'language',
          'operator' => 'in',
          'value' => ['***LANGUAGE_language_content***' => '***LANGUAGE_language_content***'],
        ],
      ],
      'fields' => [
        'title' => [
          'id' => 'title',
          'table' => 'node_field_data',
          'field' => 'title',
          'entity_type' => 'node',
          'entity_field' => 'title',
          'plugin_id' => 'field',
          'label' => '',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
        ],
      ],
    ];
    View::create([
      'id' => 'language_view',
      'label' => 'Language view',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => $display_options,
        ],
      ],
    ])->save();

    // The title and the page of the first node, in each language.
    $expected = [
      'en' => ['Alpha', 'href="/alpha"'],
      'fr' => ['Alpha FR', 'href="/alpha-fr"'],
    ];
    // English, French, then both again: the second round is served from cache.
    foreach (['en', 'fr', 'en', 'fr', 'fr', 'en'] as $round => $langcode) {
      $this->useLanguage($langcode);
      $html = $this->renderAs($this->reader, [], 'language_view');
      $other = $langcode === 'en' ? 'fr' : 'en';
      foreach ($expected[$langcode] as $shown) {
        self::assertStringContainsString($shown, $html, \sprintf('%s shows %s (round %d).', $langcode, $shown, $round));
      }
      // "Alpha" is inside "Alpha FR", so look for the title as an element.
      self::assertStringNotContainsString('>' . $expected[$other][0] . '<', $html, \sprintf('%s must not show %s (round %d).', $langcode, $expected[$other][0], $round));
      self::assertStringNotContainsString($expected[$other][1], $html, \sprintf('%s must not link to %s (round %d).', $langcode, $expected[$other][1], $round));
    }
  }

}
