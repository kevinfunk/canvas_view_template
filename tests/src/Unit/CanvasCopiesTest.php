<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Unit;

use Drupal\canvas\Form\ComponentInstanceForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fails when Canvas changes something this module has a copy of.
 *
 * Two things are copied by hand: the body of
 * ComponentInstanceForm::buildForm() and the Radix menu rules behind the row
 * menu's CSS. Neither shows up in any other test. Each check pins a
 * fingerprint of Canvas's side. A failure means "Canvas changed, compare
 * the copy", not "this module is broken". After comparing, and changing the
 * copy if needed, put the new fingerprint and Canvas version below.
 */
#[Group('canvas_view_template')]
final class CanvasCopiesTest extends TestCase {

  /**
   * The Canvas version the copies were last compared against.
   */
  private const string COMPARED_AGAINST = '1.13.0';

  /**
   * Fingerprint of ComponentInstanceForm::buildForm(), comments ignored.
   */
  private const string FORM_FINGERPRINT = 'e55971d028c22e78d14300616170f96ef341fe0068879c7389c5044d6f74666a';

  /**
   * Fingerprint of the `.rt-BaseMenu*` rules in Canvas's compiled CSS.
   */
  private const string MENU_CSS_FINGERPRINT = '1a6f90c33c85848b164a65a3a9a0df511c6ff294b03c8e8eaa2a8f87af6ef861';

  /**
   * The settings form still matches the Canvas code it was copied from.
   */
  public function testComponentInstanceFormCopyIsCurrent(): void {
    self::assertSame(
      self::FORM_FINGERPRINT,
      self::formFingerprint(),
      \sprintf('Canvas changed ComponentInstanceForm::buildForm() (last compared against Canvas %s). Compare it with src/Form/ViewTemplateComponentInstanceForm.php, update that if needed, then update FORM_FINGERPRINT and COMPARED_AGAINST here, and the version in that class and in docs/canvas-coupling.md.', self::COMPARED_AGAINST),
    );
  }

  /**
   * The row menu's CSS still matches the Radix rules in Canvas's stylesheet.
   */
  public function testRowMenuCssCopyIsCurrent(): void {
    $css = self::canvasRoot() . '/ui/dist/assets/index.css';
    if (!\is_file($css)) {
      self::markTestSkipped('Canvas has no compiled stylesheet here (ui/dist is not built).');
    }
    self::assertSame(
      self::MENU_CSS_FINGERPRINT,
      self::menuCssFingerprint((string) \file_get_contents($css)),
      \sprintf('The .rt-BaseMenu* rules in Canvas changed (last compared against Canvas %s). Compare them with the numbers in extension/dialog.css, update that if needed, then update MENU_CSS_FINGERPRINT and COMPARED_AGAINST here, and the version in dialog.css, app.css and docs/canvas-coupling.md.', self::COMPARED_AGAINST),
    );
  }

  /**
   * The Canvas module's directory.
   */
  private static function canvasRoot(): string {
    $file = (new \ReflectionClass(ComponentInstanceForm::class))->getFileName();
    self::assertIsString($file);
    return \dirname($file, 3);
  }

  /**
   * Hashes buildForm()'s code. Whitespace and comments do not count.
   */
  private static function formFingerprint(): string {
    $method = new \ReflectionMethod(ComponentInstanceForm::class, 'buildForm');
    $lines = \file((string) $method->getFileName());
    self::assertIsArray($lines);
    $code = \implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $text = '';
    foreach (\token_get_all('<?php ' . $code) as $token) {
      if (\is_array($token)) {
        if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
          continue;
        }
        $token = $token[1];
      }
      $text .= $token . ' ';
    }
    return \hash('sha256', $text);
  }

  /**
   * Hashes every rule that names a `.rt-BaseMenu*` class.
   *
   * Rules are sorted, so moving them around in the build does not count.
   */
  private static function menuCssFingerprint(string $css): string {
    $css = (string) \preg_replace('~/\*.*?\*/~s', '', $css);
    \preg_match_all('~([^{}]*\.rt-BaseMenu[^{}]*)\{([^{}]*)\}~', $css, $matches, PREG_SET_ORDER);
    self::assertNotEmpty($matches, 'No .rt-BaseMenu rules found: the stylesheet format changed.');
    $rules = [];
    foreach ($matches as $match) {
      $rules[] = \preg_replace('~\s+~', ' ', \trim($match[1])) . '{' . \preg_replace('~\s+~', ' ', \trim($match[2])) . '}';
    }
    \sort($rules);
    return \hash('sha256', \implode("\n", $rules));
  }

}
