<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Entity;

use Drupal\canvas\EntityHandlers\CanvasConfigEntityAccessControlHandler;
use Drupal\canvas_view_template\ViewTemplateUsage;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access for view templates: one in use cannot be deleted.
 *
 * Views have no config dependency on their template, so Canvas's own check
 * for dependent configuration misses them. This checks each display's Format
 * settings instead. It covers access-checked deletes (the View templates panel
 * and Canvas's API), not config import or direct `->delete()` calls.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class ViewTemplateAccessControlHandler extends CanvasConfigEntityAccessControlHandler {

  public function __construct(
    EntityTypeInterface $entity_type,
    ConfigManagerInterface $config_manager,
    private readonly EntityTypeManagerInterface $viewEntityTypeManager,
    private readonly ViewTemplateUsage $usage,
  ) {
    parent::__construct($entity_type, $config_manager, $viewEntityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(ViewTemplateUsage::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $result = parent::checkAccess($entity, $operation, $account);
    if ($operation !== 'delete') {
      return $result;
    }
    $admin_permission = $this->entityType->getAdminPermission();
    // The reason below names Views, so only those allowed to manage templates
    // see it.
    if (!\is_string($admin_permission) || !$account->hasPermission($admin_permission)) {
      return $result;
    }
    $views_list_tags = $this->viewEntityTypeManager->getDefinition('view')->getListCacheTags();
    $displays = $this->usage->displaysUsing((string) $entity->id());
    if ($displays === []) {
      return $result instanceof AccessResult ? $result->cachePerPermissions()->addCacheTags($views_list_tags) : $result;
    }
    $visible = $this->usage->visibleTo($displays, $account);
    $labels = \array_column($visible, 'label');
    $hidden = \count($displays) - \count($visible);
    if ($hidden > 0) {
      $labels[] = (string) new TranslatableMarkup('@count more you cannot access', ['@count' => $hidden]);
    }
    // Plain text: the panel shows the reason as text, so a View called
    // "News & Events" must not come out as "News &amp; Events".
    return AccessResult::forbidden(PlainTextOutput::renderFromHtml(new TranslatableMarkup(
      'This view template is used by @displays. Choose another template in their Format settings first.',
      ['@displays' => \implode(', ', $labels)],
    )))
      ->addCacheContexts(['user.permissions'])
      ->cachePerPermissions()
      ->addCacheableDependency($entity)
      ->addCacheTags($views_list_tags);
  }

}
