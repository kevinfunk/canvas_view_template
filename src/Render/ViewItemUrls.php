<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Render;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\views\Plugin\views\PluginBase;
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
    $rendering_language = (string) $view->display_handler?->getOption('rendering_language');
    if ($rendering_language === '***LANGUAGE_language_content***') {
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    }
    elseif ($rendering_language === '***LANGUAGE_language_interface***') {
      $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_INTERFACE]);
    }
    $urls = [];
    foreach ($view->result as $row) {
      $urls[] = $row instanceof ResultRow ? self::urlFor($row, $rendering_language, $cacheability) : NULL;
    }
    return $urls;
  }

  /**
   * The language a row is rendered in, per the display's "Rendering language".
   *
   * The same choice Views makes for the row's fields, so a link goes to the
   * translation whose text is shown.
   *
   * @return string|null
   *   A language code, or NULL to use the current content language.
   */
  private static function rowLanguage(EntityInterface $entity, ResultRow $row, string $rendering_language): ?string {
    if ($rendering_language === '***LANGUAGE_entity_default***') {
      return $entity instanceof ContentEntityInterface ? $entity->getUntranslated()->language()->getId() : NULL;
    }
    if ($rendering_language === '***LANGUAGE_entity_translation***') {
      // The language Views added to the query for this row.
      foreach (\get_object_vars($row) as $name => $value) {
        if (\str_ends_with($name, '_langcode') && \is_string($value) && $value !== '') {
          return $value;
        }
      }
      return NULL;
    }
    if (\str_contains($rendering_language, '***LANGUAGE_')) {
      // The two "current language" choices; their cache context is set above.
      return PluginBase::queryLanguageSubstitutions()[$rendering_language] ?? NULL;
    }
    return $rendering_language !== '' ? $rendering_language : NULL;
  }

  /**
   * The row's entity page, if it has one the current user can view.
   */
  private static function urlFor(ResultRow $row, string $rendering_language, CacheableMetadata $cacheability): ?string {
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
      $langcode = self::rowLanguage($entity, $row, $rendering_language);
      if ($langcode === NULL) {
        $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
      }
      $entity = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $langcode);
      \assert($entity instanceof EntityInterface);
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
