# Changing a component

These components ship as config and their sources are not discovered, so Canvas
never updates them. A change to a component's slot definitions or settings
schema changes its version hash, so put the new hash as `active_version` in the
shipped config (`config/install`). Sites that already have the module also need
an update hook that creates the new version on the existing Component entity
and makes it active, and moves placements if the component is replaced. Slot
titles and descriptions are plain English for this reason: translated, the hash
would depend on the interface language.

Update hook numbers start at 10001 and go up by one. The module has no update
hooks yet: the first one is added with the first component change after a site
installs the module.

`Drupal\canvas_view_template\Update\ComponentUpdater` does the work, in trees
and in unpublished drafts, and is safe to run twice:

```php
/**
 * Adds the "Element" setting to "View title".
 */
function canvas_view_template_update_10001(): void {
  $updater = \Drupal::service(ComponentUpdater::class);
  $old_version = Component::load('canvas_view_template_title.view_title')->getActiveVersion();
  if ($updater->updateToShipped('canvas_view_template_title.view_title')) {
    $updater->migratePlacements('canvas_view_template_title.view_title', $old_version, [
      'element' => 'h2',
    ]);
  }
}
```

Use `movePlacements()` instead when one component replaces another, before
deleting the old one, and `addMissing()` when a release only adds components.
`ViewTemplateComponentUpdaterTest` simulates an older site.
