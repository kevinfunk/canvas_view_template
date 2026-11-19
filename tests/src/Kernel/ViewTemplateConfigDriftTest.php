<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Config\TypedConfigManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the shipped component config against the plugins that produce it.
 */
#[Group('canvas_view_template')]
final class ViewTemplateConfigDriftTest extends ViewTemplateKernelTestBase {

  use SchemaCheckTrait;

  private const string PREFIX = 'canvas_view_template_';

  /**
   * Reads the shipped component config, keyed by component ID.
   *
   * @return array<string, array<string, mixed>>
   *   The parsed config.
   */
  private static function shippedConfig(): array {
    $shipped = [];
    foreach (\glob(__DIR__ . '/../../../config/install/canvas.component.*.yml') ?: [] as $file) {
      $id = \substr(\basename($file, '.yml'), \strlen('canvas.component.'));
      $shipped[$id] = Yaml::parseFile($file);
    }
    return $shipped;
  }

  /**
   * Each plugin has config, and each config names a plugin.
   */
  public function testPluginsAndConfigMatchUp(): void {
    $plugin_ids = \array_filter(
      \array_keys($this->container->get(ComponentSourceManager::class)->getDefinitions()),
      static fn (string $id): bool => \str_starts_with($id, self::PREFIX),
    );
    $used = \array_unique(\array_column(self::shippedConfig(), 'source'));
    \sort($plugin_ids);
    \sort($used);
    self::assertSame($plugin_ids, $used);
  }

  /**
   * The shipped config says what the plugin and the install say.
   */
  public function testShippedConfigMatchesPlugin(): void {
    $shipped = self::shippedConfig();
    self::assertNotEmpty($shipped);
    foreach ($shipped as $id => $config) {
      self::assertSame($id, $config['id'], $id);
      self::assertSame($config['source'] . '.' . $config['source_local_id'], $id, $id);
      self::assertSame('canvas_view_template', $config['provider'], $id);
      self::assertSame(['canvas_view_template'], $config['dependencies']['enforced']['module'], $id);

      $component = Component::load($id);
      self::assertInstanceOf(Component::class, $component, "$id was not installed.");
      $source = $component->getComponentSource();
      self::assertSame($config['source'], $source->getPluginId(), $id);
      self::assertSame($config['source_local_id'], $source->getSourceSpecificComponentId(), $id);
      self::assertSame((string) $source->getComponentDescription(), $config['label'], $id);
      self::assertSame($source->generateVersionHash(), $config['active_version'], "$id: the version hash in the YAML is stale.");

      $slots = $source instanceof ComponentSourceWithSlotsInterface ? $source->getSlotDefinitions() : [];
      self::assertEquals($slots, $config['versioned_properties']['active']['fallback_metadata']['slot_definitions'], "$id: slots differ from the plugin's.");
      self::assertEquals($config['versioned_properties']['active']['settings'], $component->getSettings(), $id);
      self::assertSame($config['active_version'], $component->getActiveVersion(), $id);
    }
  }

  /**
   * Each component sits in the folder its plugin names.
   */
  public function testComponentsAreInTheirFolder(): void {
    foreach (\array_keys(self::shippedConfig()) as $id) {
      $component = Component::load($id);
      self::assertInstanceOf(Component::class, $component, $id);
      $name = $component->getComponentSource()->determineDefaultFolder();
      $folder = Folder::loadByNameAndConfigEntityTypeId($name, Component::ENTITY_TYPE_ID);
      self::assertInstanceOf(Folder::class, $folder, "No \"$name\" folder for $id.");
      self::assertContains($id, $folder->get('items'), "$id is not in the \"$name\" folder.");
    }
  }

  /**
   * Each installed component passes the config schema.
   */
  public function testConfigSchemaResolves(): void {
    $typed = $this->container->get(TypedConfigManagerInterface::class);
    foreach (\array_keys(self::shippedConfig()) as $id) {
      $name = 'canvas.component.' . $id;
      self::assertTrue($typed->hasConfigSchema($name), $name);
      $result = $this->checkConfigSchema($typed, $name, $this->config($name)->get());
      self::assertTrue($result === TRUE, $name . ': ' . \print_r($result, TRUE));
      $violations = $typed->createFromNameAndData($name, $this->config($name)->get())->validate();
      self::assertCount(0, $violations, $name . ': ' . \implode('; ', \array_map(static fn ($v): string => $v->getPropertyPath() . ' ' . $v->getMessage(), \iterator_to_array($violations))));
    }
  }

}
