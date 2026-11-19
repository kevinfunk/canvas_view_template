<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_view_template\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Plugin\Canvas\ComponentSource\ViewTemplatePlaceholder;
use Drupal\canvas_view_template\ViewTemplateDrafts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests finding the version of a view template the editor is working on.
 */
#[CoversClass(ViewTemplateDrafts::class)]
#[Group('canvas_view_template')]
final class ViewTemplateDraftsTest extends ViewTemplateKernelTestBase {

  /**
   * The service under test.
   */
  private ViewTemplateDrafts $drafts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drafts = $this->container->get(ViewTemplateDrafts::class);
  }

  /**
   * Saves a published template with one "View rows".
   */
  private function createPublished(string $id): ViewTemplate {
    $template = ViewTemplate::create([
      'id' => $id,
      'label' => $id,
      'component_tree' => [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_ROWS_LOCAL_ID)],
    ]);
    $template->save();
    return $template;
  }

  /**
   * Saves an unpublished draft with one "View pager" in place of the rows.
   *
   * @return string[]
   *   The draft's tree uuids.
   */
  private function saveDraft(ViewTemplate $template): array {
    $tree = [$this->placeholderItem(ViewTemplatePlaceholder::VIEW_PAGER_LOCAL_ID)];
    $draft = ViewTemplate::load((string) $template->id());
    self::assertNotNull($draft);
    $draft->setComponentTree($tree);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    return \array_column($tree, 'uuid');
  }

  /**
   * A template with no draft is its own latest version.
   */
  public function testPublishedWhenNoDraft(): void {
    $template = $this->createPublished('no_draft');
    self::assertSame($template, $this->drafts->latest($template));
  }

  /**
   * The draft wins over the published tree, and leaves it untouched.
   */
  public function testDraftPreferredOverPublished(): void {
    $template = $this->createPublished('with_draft');
    $published = \array_column($template->getComponentTree()->getValue(), 'uuid');
    $draft_uuids = $this->saveDraft($template);

    $latest = $this->drafts->latest($template);
    self::assertNotSame($template, $latest);
    self::assertSame('with_draft', $latest->id());
    self::assertSame($draft_uuids, \array_column($latest->getComponentTree()->getValue(), 'uuid'));
    self::assertSame($published, \array_column(ViewTemplate::load('with_draft')?->getComponentTree()->getValue() ?? [], 'uuid'));
  }

  /**
   * After the draft is discarded, the published template is the latest again.
   */
  public function testPublishedAgainAfterDraftDiscarded(): void {
    $template = $this->createPublished('discarded');
    $this->saveDraft($template);
    self::assertNotSame($template, $this->drafts->latest($template));

    $this->container->get(AutoSaveManager::class)->delete($template);
    self::assertSame($template, $this->drafts->latest($template));
  }

  /**
   * A template that was never saved has no draft either.
   */
  public function testUnsavedTemplateIsItsOwnLatest(): void {
    $template = ViewTemplate::create(['id' => 'never_saved', 'label' => 'Never saved']);
    self::assertSame($template, $this->drafts->latest($template));
  }

  /**
   * A draft of one template is not mistaken for another's.
   */
  public function testDraftBelongsToItsTemplate(): void {
    $with = $this->createPublished('has_draft');
    $without = $this->createPublished('has_none');
    $this->saveDraft($with);

    self::assertNotSame($with, $this->drafts->latest($with));
    self::assertSame($without, $this->drafts->latest($without));
  }

}
