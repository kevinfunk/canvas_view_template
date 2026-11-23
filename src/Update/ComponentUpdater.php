<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Helpers for update hooks that change this module's components.
 *
 * The components ship as config whose sources are not discovered, so Canvas
 * never updates them. After a change to a component's slots or settings (which
 * changes its version hash), an update hook calls these, in this order:
 * - ::updateToShipped() adds the new version to the existing Component and
 *   makes it active, from `config/install`.
 * - ::migratePlacements() points placements of the old version at it, with the
 *   inputs the new settings need. Or, when a component is replaced by another,
 *   ::movePlacements().
 * Both are safe to run twice.
 *
 * @see docs/changing-a-component.md
 */
final class ComponentUpdater {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ExtensionPathResolver $pathResolver,
    private readonly ConfigInstallerInterface $configInstaller,
  ) {}

  /**
   * Installs shipped components the site does not have yet.
   *
   * Existing ones are left alone.
   */
  public function addMissing(): void {
    $this->configInstaller->installDefaultConfig('module', 'canvas_view_template');
  }

  /**
   * Makes the shipped version of an installed component its active version.
   *
   * Adds a version with the shipped settings and keeps the old ones, so
   * placements of the old version keep working until they are migrated. A
   * component the site lacks is installed.
   *
   * @param string $component_id
   *   The Component id, for example `canvas_view_template_title.view_title`.
   *
   * @return bool
   *   Whether anything changed.
   */
  public function updateToShipped(string $component_id): bool {
    $shipped = $this->shipped($component_id);
    $component = Component::load($component_id);
    if ($component === NULL) {
      $this->addMissing();
      return TRUE;
    }
    $version = $shipped['active_version'];
    if ($component->getActiveVersion() === $version) {
      return FALSE;
    }
    $component->createVersion($version);
    $component->setSettings($shipped['versioned_properties']['active']['settings'] ?? []);
    if (isset($shipped['label'])) {
      $component->set('label', $shipped['label']);
    }
    $component->save();
    return TRUE;
  }

  /**
   * Moves placements of an older version of a component to its active one.
   *
   * @param string $component_id
   *   The Component id.
   * @param string $old_version
   *   The version being left.
   * @param array<string, mixed>|callable $new_inputs
   *   The `inputs` for moved placements, or a function that maps the old ones.
   */
  public function migratePlacements(string $component_id, string $old_version, array|callable $new_inputs): void {
    $this->rewritePlacements($component_id, $old_version, $component_id, $new_inputs);
  }

  /**
   * Points every placement of one component at another.
   *
   * Do this before deleting the old component: deleting it deletes everything
   * that depends on it.
   *
   * @param string $old_id
   *   The Component id being replaced (it may already be gone).
   * @param string $new_id
   *   The Component id that replaces it (it must exist).
   * @param array<string, mixed>|callable $new_inputs
   *   The `inputs` for moved placements, or a function that maps the old ones.
   */
  public function movePlacements(string $old_id, string $new_id, array|callable $new_inputs): void {
    $this->rewritePlacements($old_id, NULL, $new_id, $new_inputs);
  }

  /**
   * The component's config as shipped in config/install.
   *
   * @return array<string, mixed>
   *   The decoded YAML.
   */
  private function shipped(string $component_id): array {
    $file = $this->pathResolver->getPath('module', 'canvas_view_template') . '/config/install/canvas.component.' . $component_id . '.yml';
    if (!\is_file($file)) {
      throw new \InvalidArgumentException(\sprintf('No shipped component "%s".', $component_id));
    }
    return Yaml::parseFile($file);
  }

  /**
   * Rewrites placements in every tree, and in the unpublished drafts.
   *
   * Covers every config entity with a component tree (view templates, patterns,
   * content and page templates, page regions), not content entities such as
   * Canvas pages. A placement of a missing component stops the editor loading
   * the tree.
   */
  private function rewritePlacements(string $old_id, ?string $old_version, string $new_id, array|callable $new_inputs): void {
    $new_component = Component::load($new_id);
    if ($new_component === NULL) {
      return;
    }
    $new_version = $new_component->getActiveVersion();
    $rewrite = static function (array $tree) use ($old_id, $old_version, $new_id, $new_version, $new_inputs): ?array {
      $changed = FALSE;
      foreach ($tree as $key => $item) {
        if (($item['component_id'] ?? NULL) !== $old_id) {
          continue;
        }
        if ($old_version !== NULL && ($item['component_version'] ?? NULL) !== $old_version) {
          continue;
        }
        $tree[$key]['component_id'] = $new_id;
        $tree[$key]['component_version'] = $new_version;
        $tree[$key]['inputs'] = \is_callable($new_inputs) ? $new_inputs($item['inputs'] ?? []) : $new_inputs;
        $changed = TRUE;
      }
      return $changed ? $tree : NULL;
    };

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!\is_subclass_of($definition->getClass(), ComponentTreeConfigEntityBase::class)) {
        continue;
      }
      foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple() as $entity) {
        if (!$entity instanceof ConfigEntityInterface) {
          continue;
        }
        // Read the draft first: saving a tree discards it.
        $draft = $this->autoSaveManager->getAutoSaveEntity($entity, TRUE);
        // Raw data: the tree may point at a missing component, which would fail
        // if hydrated.
        $tree = $rewrite($entity->toArray()['component_tree'] ?? []);
        if ($tree !== NULL) {
          $entity->set('component_tree', $tree)->save();
        }
        $draft_entity = $draft->entity;
        if ($draft->isEmpty() || !$draft_entity instanceof ConfigEntityInterface) {
          continue;
        }
        $draft_tree = $rewrite($draft_entity->toArray()['component_tree'] ?? []);
        if ($draft_tree !== NULL) {
          $draft_entity->set('component_tree', $draft_tree);
        }
        if ($draft_tree !== NULL || $this->autoSaveManager->getAutoSaveEntity($entity, TRUE)->isEmpty()) {
          // The draft's owner and time become the current user and now: the
          // AutoSaveManager API has no way to keep them.
          $this->autoSaveManager->saveEntity($draft_entity, $draft->clientId);
        }
      }
    }
  }

}
