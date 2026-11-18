<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\EmptyTargetEntityProviderInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a named, reusable Canvas layout for a Drupal Views display.
 *
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder
 * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('View template'),
  label_singular: new TranslatableMarkup('view template'),
  label_plural: new TranslatableMarkup('view templates'),
  label_collection: new TranslatableMarkup('View templates'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => ViewTemplateAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'component_tree',
  ],
  constraints: [
    'ImmutableProperties' => [
      'properties' => ['id'],
    ],
  ],
)]
final class ViewTemplate extends ComponentTreeConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, EmptyTargetEntityProviderInterface {

  public const string ENTITY_TYPE_ID = 'view_template';

  /**
   * The permission to administer view templates.
   */
  public const string ADMIN_PERMISSION = 'administer view template';

  use ClientServerConversionTrait;

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human-readable label.
   */
  protected ?string $label = NULL;

  /**
   * An optional description shown in the view template management UI.
   */
  protected ?string $description = NULL;

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(): ComponentTreeItemList {
    $field_items = $this->createDanglingComponentTreeItemList($this);
    $field_items->setValue(\array_values($this->component_tree ?? []));
    return $field_items;
  }

  /**
   * Builds a renderable list from an already-rooted item subset.
   *
   * @param array $items
   *   The component tree items; top-level ones must have `parent_uuid: NULL`.
   *
   * @see \Drupal\canvas_view_template\Render\ViewTemplateRenderer::renderRepeater()
   */
  public function getComponentSubtree(array $items): ComponentTreeItemList {
    $field_items = $this->createDanglingComponentTreeItemList($this);
    $field_items->setValue(\array_values($items));
    return $field_items;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id(),
        'label' => $this->label(),
        'description' => $this->description,
        'status' => $this->status(),
        'component_tree' => $this->getComponentTree()->getValue(),
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromClientSide(array $data): static {
    $values = [];
    foreach (['id', 'label', 'description'] as $key) {
      if (\array_key_exists($key, $data)) {
        $values[$key] = $data[$key];
      }
    }
    $entity = static::create($values);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function updateFromClientSide(array $data): void {
    foreach (['label', 'description'] as $key) {
      if (\array_key_exists($key, $data)) {
        $this->set($key, $data[$key]);
      }
    }
    if (\array_key_exists('component_tree', $data)) {
      $this->setComponentTree($data['component_tree'] ?? []);
    }
    if (\array_key_exists('status', $data)) {
      $this->setStatus($data['status']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // No theme dependency: the full list is always relevant.
  }

  /**
   * {@inheritdoc}
   *
   * Trees have no host entity, so an empty canvas page stands in.
   */
  public function createEmptyTargetEntity(): FieldableEntityInterface {
    $entity = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->create([]);
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

}
