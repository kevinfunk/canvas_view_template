<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas_view_template\Controller\ViewTemplatePanelStringsController;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the table that translates the View templates panel.
 */
#[CoversClass(ViewTemplatePanelStringsController::class)]
#[Group('canvas_view_template')]
final class ViewTemplatePanelStringsTest extends ViewTemplateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_mode',
    'canvas_view_template',
    'language',
    'locale',
    'node',
    'views',
  ];

  /**
   * Every string the panel asks for is in the table, and nothing else is.
   */
  public function testTableMatchesWhatThePanelUses(): void {
    $dir = \dirname(__DIR__, 3) . '/extension';
    $js = '';
    foreach (\glob($dir . '/*.js') ?: [] as $file) {
      $js .= (string) \file_get_contents($file);
    }
    $html = (string) \file_get_contents($dir . '/index.html');

    $used = [];
    \preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", $js, $calls);
    foreach ($calls[1] as $text) {
      $used[] = \stripslashes($text);
    }
    \preg_match_all('/data-t(?:-aria-label)?="([^"]*)"/', $html, $attributes);
    foreach ($attributes[1] as $text) {
      $used[] = \html_entity_decode($text, ENT_QUOTES);
    }
    // Set for the page title in start().
    $used[] = 'View templates';
    $listed = \array_map(
      static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
      ViewTemplatePanelStringsController::sources(),
    );
    self::assertSame([], \array_values(\array_diff($used, $listed)), 'Used by the panel but missing from the table.');
    self::assertSame([], \array_values(\array_diff($listed, $used)), 'In the table but not used by the panel.');
    self::assertSame($listed, \array_values(\array_unique($listed)), 'Listed twice.');
  }

  /**
   * The table is in the current language, and keeps placeholders.
   */
  public function testTableIsTranslatedAndKeepsPlaceholders(): void {
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location']);
    $this->installConfig(['language', 'locale']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $storage = $this->container->get('locale.storage');
    $source = $storage->createString(['source' => 'Actions for @name', 'context' => ''])->save();
    $storage->createTranslation(['lid' => $source->lid, 'language' => 'fr', 'translation' => 'Actions pour @name'])->save();
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->container->get('language.default')->set(ConfigurableLanguage::load('fr'));
    $this->container->get('language_manager')->reset();
    $this->container->get('string_translation')->setDefaultLangcode('fr');

    $data = \json_decode((string) ViewTemplatePanelStringsController::create($this->container)->strings()->getContent(), TRUE);
    self::assertSame('Actions pour @name', $data['strings']['Actions for @name']);
    self::assertSame('Cancel', $data['strings']['Cancel']);
    self::assertSame('ltr', $data['dir']);
    self::assertArrayHasKey('langcode', $data);
  }

}
