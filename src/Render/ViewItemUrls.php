<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;

/**
 * Works out the page each result item links to.
 *
 * For "View item link". A row without an entity page the current user can
 * view (e.g. a database row) has none.
 *
 * @see \Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplateItemLink
 */
final class ViewItemUrls {

  /**
   * The URL of each result row, in order.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects what the URLs depend on (access, language).
   *
   * @return list<string|null>
   *   The row's canonical page, or NULL when it has none.
   */
  public static function collect(ViewExecutable $view, ?CacheableMetadata $cacheability = NULL): array {
    $cacheability ??= new CacheableMetadata();
    $urls = [];
    foreach ($view->result as $row) {
      $urls[] = $row instanceof ResultRow ? self::urlFor($row, $cacheability) : NULL;
    }
    return $urls;
  }

  /**
   * The row's entity page, if it has one the current user can view.
   */
  private static function urlFor(ResultRow $row, CacheableMetadata $cacheability): ?string {
    $entity = $row->_entity ?? NULL;
    if (!$entity instanceof EntityInterface || !$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    // Some canonical routes are the edit form (media without standalone
    // URLs): not a page to send visitors to.
    $entity_type = $entity->getEntityType();
    if ($entity_type->getLinkTemplate('canonical') === $entity_type->getLinkTemplate('edit-form')) {
      return NULL;
    }
    if ($entity instanceof TranslatableInterface && $entity->isTranslatable()) {
      $entity = \Drupal::service('entity.repository')->getTranslationFromContext($entity);
      \assert($entity instanceof EntityInterface);
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    }
    $access = $entity->access('view', NULL, TRUE);
    $cacheability->addCacheableDependency($access)->addCacheableDependency($entity);
    if (!$access->isAllowed()) {
      return NULL;
    }
    $url = $entity->toUrl('canonical')->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    return $url->getGeneratedUrl();
  }

}
