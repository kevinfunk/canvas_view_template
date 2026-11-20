<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The View templates panel's text, in the current interface language.
 *
 * The panel is a static page, so it cannot call t(). It asks for this table,
 * keyed by the English text, and falls back to English without it. Every
 * `t('...')` in extension/app.js and every `data-t` in extension/index.html
 * must be listed here; a test checks it.
 *
 * @see extension/app.js
 */
final class ViewTemplatePanelStringsController extends ControllerBase {

  /**
   * Returns the table, the language code and its direction.
   */
  public function strings(): JsonResponse {
    $strings = [];
    foreach (self::sources() as $markup) {
      $strings[$markup->getUntranslatedString()] = (string) $markup;
    }
    $language = $this->languageManager()->getCurrentLanguage();
    $response = new JsonResponse([
      'langcode' => $language->getId(),
      'dir' => $language->getDirection(),
      'strings' => $strings,
    ]);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * The panel's text. `@name` marks a placeholder the panel fills in.
   *
   * @return list<\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   One entry per string.
   */
  public static function sources(): array {
    return [
      new TranslatableMarkup('View templates'),
      new TranslatableMarkup('Close panel'),
      new TranslatableMarkup("Canvas layouts that a Views display renders through. Choose one under the view's Format settings."),
      new TranslatableMarkup('New view template'),
      new TranslatableMarkup('Try again'),
      new TranslatableMarkup('Loading…'),
      new TranslatableMarkup('Working…'),
      new TranslatableMarkup('Search view templates'),
      new TranslatableMarkup('Search…'),
      new TranslatableMarkup('New'),
      new TranslatableMarkup('No view templates match your filter.'),
      new TranslatableMarkup("No view templates yet. Create one, then choose it under a view's Format settings."),
      new TranslatableMarkup('@count more'),
      new TranslatableMarkup('Actions for @name'),
      new TranslatableMarkup('Cancel'),
      new TranslatableMarkup('(optional)'),
      new TranslatableMarkup('Usage unknown'),
      new TranslatableMarkup('Used by @views'),
      new TranslatableMarkup('Not used'),
      new TranslatableMarkup('Edit'),
      new TranslatableMarkup('Duplicate'),
      new TranslatableMarkup('Delete'),
      new TranslatableMarkup('1 view template.'),
      new TranslatableMarkup('@count view templates.'),
      new TranslatableMarkup('Could not load view templates: @error'),
      new TranslatableMarkup('Template is in use'),
      new TranslatableMarkup('"@name" is used by @views. Choose another template in the Format settings of that view first, then delete it.'),
      new TranslatableMarkup('"@name" is used by @views. Choose another template in the Format settings of those views first, then delete it.'),
      new TranslatableMarkup('OK'),
      new TranslatableMarkup('Delete template'),
      new TranslatableMarkup('Are you sure you want to delete "@name"? This action cannot be undone.'),
      new TranslatableMarkup('Could not delete "@name": @error'),
      new TranslatableMarkup('Deleted "@name".'),
      new TranslatableMarkup('Enter a label.'),
      new TranslatableMarkup('The machine name must start with a letter and use only lowercase letters, numbers and underscores.'),
      new TranslatableMarkup('A view template with the machine name "@id" already exists.'),
      new TranslatableMarkup('Could not create the view template: @error'),
      new TranslatableMarkup('Could not duplicate the view template: @error'),
      new TranslatableMarkup('Copy of @label'),
      new TranslatableMarkup('Duplicate view template'),
      new TranslatableMarkup('Starts from what the editor shows now, including changes not yet published. Views keep using the original until you choose the copy in their Format settings.'),
      new TranslatableMarkup('Label'),
      new TranslatableMarkup('Machine name'),
      new TranslatableMarkup('Description'),
      new TranslatableMarkup('Duplicate and edit'),
      new TranslatableMarkup('Create and edit'),
    ];
  }

}
