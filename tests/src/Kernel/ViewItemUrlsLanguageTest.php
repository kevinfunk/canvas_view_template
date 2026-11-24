<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Render\ViewItemUrls;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Drupal\views\ResultRow;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that item links follow the display's "Rendering language".
 */
#[CoversClass(ViewItemUrls::class)]
#[Group('canvas_view_template')]
#[RunTestsInSeparateProcesses]
final class ViewItemUrlsLanguageTest extends ViewTemplateKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installNodeSupport();
    $this->installConfig(['language']);
    $this->setUpCurrentUser(['uid' => 2], ['access content']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
  }

  /**
   * Each row links to the translation Views renders it in.
   */
  #[DataProvider('renderingLanguageProvider')]
  public function testLinkFollowsRenderingLanguage(string $rendering_language, string $row_langcode, string $expected, array $contexts): void {
    $node = Node::create(['type' => 'article', 'title' => 'English', 'status' => 1]);
    $node->addTranslation('fr', ['title' => 'Français'])->save();
    $node->save();
    $id = $node->id();
    foreach (['en' => 'english', 'fr' => 'francais'] as $langcode => $alias) {
      PathAlias::create(['path' => '/node/' . $id, 'alias' => '/' . $alias, 'langcode' => $langcode])->save();
    }

    View::create([
      'id' => 'language_urls',
      'label' => 'Language urls',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => ['rendering_language' => $rendering_language],
        ],
      ],
    ])->save();
    $view = Views::getView('language_urls');
    self::assertNotNull($view);
    $view->setDisplay('default');
    $view->result = [new ResultRow(['_entity' => $node, 'node_field_data_langcode' => $row_langcode])];

    $cacheability = new CacheableMetadata();
    self::assertSame([$expected], ViewItemUrls::collect($view, $cacheability));
    foreach ($contexts as $context) {
      self::assertContains($context, $cacheability->getCacheContexts());
    }
  }

  /**
   * Rendering languages, the row's language, the link, and cache contexts.
   *
   * @return array<string, array{string, string, string, list<string>}>
   *   The cases.
   */
  public static function renderingLanguageProvider(): array {
    return [
      'row translation, French row' => ['***LANGUAGE_entity_translation***', 'fr', '/francais', []],
      'row translation, English row' => ['***LANGUAGE_entity_translation***', 'en', '/english', []],
      'original language' => ['***LANGUAGE_entity_default***', 'fr', '/english', []],
      'fixed language' => ['fr', 'en', '/francais', []],
      'fixed language with no translation falls back' => ['de', 'fr', '/english', []],
      'content language' => ['***LANGUAGE_language_content***', 'fr', '/english', ['languages:language_content']],
      'interface language' => ['***LANGUAGE_language_interface***', 'fr', '/english', ['languages:language_interface']],
    ];
  }

}
