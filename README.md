# Canvas View Template

## Table of contents

- Introduction
- Requirements
- Recommended modules
- Installation
- Configuration
- Uninstalling
- How it works
- Keeping the panel in step with Canvas
- Coupling to Canvas
- Known limitations
- Changing a component
- Testing


## Introduction

Canvas View Template lets you build the layout of a Drupal Views
display — rows, exposed filters, pager — visually, using Canvas's
drag-and-drop editor, the same way you'd design any other page in
Canvas.

Without this module, a View's rows render as one opaque block: Canvas
has no way to reposition the exposed filter form or pager relative to
the rows, and no way to control what wraps each individual row. This
module adds:

- A new `View template` entity, edited in Canvas's own editor.
- Sixteen new Library components for use inside a view template:
  `View rows`, `View exposed filters`, `View pager`, `View header`,
  `View footer`, `View more link`, `View attachments`, `View feed icons`, `View condition` (a container shown only when a condition is met), `View item link` (a container linking its contents to the current item's page), `View no results` (a container shown
  only when the view has no results), `View title`, `View item` (a single
  item, meant to be repeated), `View field` (one field of the current item),
  `View result summary` ("Displaying 1 - 10 of 54"), and `View repeater`
  (repeats its contents once per item, with its own limit/offset and an
  optional page condition — e.g. "only on page 1").
- A `Canvas view template` option in the Views UI's Format list, to
  select which view template a display renders through.
- A `Page template` option alongside it, to override which Canvas page
  template wraps that specific display when it renders as a page.


## Requirements

This module requires the following modules:

- [Views](https://www.drupal.org/docs/8/core/modules/views) (Drupal core)
- [Canvas](https://www.drupal.org/project/canvas) 1.13.x, with Drupal 11.4.
  The info file allows only `>=1.13, <1.14`, because this module uses Canvas
  internals that have no stability promise yet (see "Coupling to Canvas").
  The constraint matches releases only: a git checkout or `1.x-dev` of Canvas
  does not satisfy it, so to test against one, change the constraint in a
  local copy of `canvas_view_template.info.yml`.

### Component config and Canvas's schema

The components this module ships use a component source that Canvas's config
schema does not list yet. The schema's list of allowed sources is only lifted by
Canvas's development module `canvas_dev_mode`, until Canvas makes component
sources a public API ([drupal.org/i/3520484](https://www.drupal.org/i/3520484)).

On a normal site that has no effect: config saves are not validated, and the
components install, render and can be edited. Anything that validates config
(strict schema checks in tests, the Config Inspector module, a recipe that
validates its config) reports `source` as an invalid choice on all 16
`canvas.component.canvas_view_template_*` components, and nothing else. If that
blocks you, skip validation for those components, or enable `canvas_dev_mode`
on that environment only: it is a development module, not for production.
`ViewTemplateWithoutDevModeTest` records this behaviour, so a Canvas update that
changes it fails a test.


## Recommended modules

- [View Pager Plugin](https://www.drupal.org/project/view_pager_plugin):
  when a view template uses "View repeater" with a page condition (e.g.
  featured items on page 1, everything else on later pages), the
  standard Views pager always fetches the same number of rows per page.
  View Pager Plugin's "Page Control" pager lets the first page fetch a
  different number of rows than every page after it, so nothing is ever
  dropped or duplicated at the page boundary.


## Installation

Install it with Composer, then enable it:

```bash
composer require drupal/canvas_view_template
drush en canvas_view_template
```

Enabling installs the module's Library components and the "View template"
library folder. Older builds had update hooks up to 10017; they were removed
before the first release, so there is no upgrade path from those builds:
reinstall instead.

### Moving between environments (config sync)

Enable the module once, on the environment whose config you export, and let
every other environment get it through `drush config:import`, which enables the
module because it is listed in `core.extension`. Do not also run `drush en` on
each environment before importing: that creates the components and the library
folder there with their own UUIDs, and the import then fails on every one of
them with "different UUID" errors. A new site built from exported config uses
`drush site:install --existing-config`.

Do not put `canvas.component.canvas_view_template_*` or `canvas.folder.*` in
`config_ignore`: with those ignored, an environment can end up with two "View
template" folders or with components that differ from the code.

### Not an API for other modules

The module's classes are `final` and it has no hooks, events or plugin types of
its own for other modules to use. Customize with your own Canvas components, or
with a theme override of `views-view--canvas-view-template.html.twig`.


## Configuration

1. Enable the module.
2. In the Canvas editor, open the **View templates** tool in the left
   rail (`/canvas/app/view_templates`) and choose **New view template**.
   Give it a label and you'll be taken straight into the editor.
3. In the editor, drag in `View rows` (required for the rows to
   render), and optionally `View exposed filters`, `View pager`, or one
   or more `View repeater` components (each with a `View item` placed
   inside).
4. Edit the View you want to template, and under its display's
   **Format**, choose **Canvas view template**. Select the view
   template you built, and optionally a **Page template** override.


## Uninstalling

Drupal deletes any View that depends on a module being uninstalled, and a View
depends on the module providing its style. So uninstall is blocked while a
display uses the **Canvas view template** format, with a message listing those
displays. Switch their **Format** to another style first; then uninstalling
deletes the view templates and this module's components, along with the empty
"View template" library folder.


## How it works

`View rows` / `View exposed filters` / `View pager` are placeholders:
when the View renders, this module injects the View's already-rendered
rows, exposed filter form, and pager into wherever each placeholder is
placed in the tree.

`View repeater` is different: it re-renders its own contents once per
item (one result row of the View), feeding a nested `View item` a
different item on each repetition. A
view template can contain more than one `View repeater` — each is
resolved independently, so a "2 featured cards, then the rest as a
list" layout is just two repeaters with different `limit`/`offset`
settings. A `View repeater` can't be placed inside another one (at any depth,
even with a container or `View condition` in between): the inner one would
have no items of its own to repeat. The template can't be published until it is
moved beside the first one, or `View item` and `View field` are used inside
the first one instead. Components that show the whole view's output (`View
rows`, `View exposed filters`, `View pager`, `View header`, `View footer`,
`View more link`, `View attachments`, `View no results`, `View title`, `View
result summary` and `View feed icons`) can't be placed inside a repeater
either: they would show the whole view on each item, or nothing. Place them
beside it. `View item`, `View field`, `View condition`, `View item link` and
ordinary Canvas components are fine inside.

**Rows or a repeater?** `View rows` shows the items exactly as the view's
own Format renders them: a table, a list, a grid, grouped rows, or a style
another module adds, along with the view's "no results" text. It is the
quick start, and one component is all a template needs. `View repeater`
lets you lay out each item yourself, with `View item` (the whole item as the
view renders it) or `View field` (one field) inside it. The two can be
combined, for example a repeater of featured items above `View rows`.

**Conditions.** `View condition` is a container, like `View no results`, that
shows what you put in it only when a condition is true, and leaves nothing
behind (not even a wrapper) when it is not. Choose the condition in **Show
when**: the view has results, the results have more than one page, it is the
first page, it is not the first page, exposed filters are applied (only what a
visitor submitted counts, not a filter's default), or the view has a header,
footer, "more" link, attachments or a feed. That last group is for keeping a
heading or divider from showing next to something that is empty, for example a
"More from this section" title that only appears when the "more" link does.
Conditions also work inside a `View repeater`. To react to *no* results, use
`View no results`; to repeat on particular pages, use the repeater's own page
condition.

**Linking a whole item.** `View item link` is a container, like `View condition`:
whatever you put inside links to the current item's page. Inside a `View
repeater` each repeated item links to its own page; elsewhere it links to the
first item. Its **Open in** setting is the same tab or a new one. An item
with no page of its own, or one the visitor may not view, is shown without a
link, as are media items whose page is only their edit form (when standalone
media URLs are off). Links use the visitor's language version of the item.
HTML does not allow a link inside a link, so put content without links of its
own inside (for a title that is a link, use `View field` outside the wrapper,
or the wrapper alone). The module does not provide card or teaser layouts:
build those from Canvas's components, with `View repeater`, `View item`,
`View field` and `View item link` supplying the view's data.

**Everything else Views builds.** A template shows what you place in it.
`View header`, `View footer` and `View title` put those pieces exactly where
you want them, and a view's header and footer appear nowhere else. A view
only has them if you add them in its Header and Footer settings. The same
goes for `View more link`, the view's "more" link (for example "More
articles"). The view has to have its link turned on (in the view's Pager
settings, "Create more link"), or there is nothing to show. `View attachments`
shows the view's Attachment displays, the ones attached before the main
display first and then those attached after it. Add an Attachment display to
the view and attach it to this display, or there is nothing to show. `View feed
icons` shows the feed icon of a Feed display attached to this display, or
nothing if there is none. Its **Label** setting (Visible or Hidden) can hide the feed's title next
to the icon (it stays available to screen readers); that only applies in
themes that mark the title up as a label, such as Olivero. The view's
title is never printed inside a view by Views, so it only appears where
`View title` is placed, as a heading: its **Element** setting picks the
level (h1 to h6, h2 by default, since on a page display the page's own
title is usually the h1) or plain text with no element. The view is also wrapped in the same element as
Views' own template, which Views' AJAX (pagers, exposed filters, "load
more") and contextual links rely on.

**No results.** `View no results` is a container: drop any components into it
(a heading, some text, a button) and they appear only when the view has no
results; with results it renders nothing. If you place it but drop nothing
in, it shows the "no results" content configured in the Views UI. Without a
`View no results`, `View rows` shows that Views content in place of the rows.

**Single fields.** `View item` shows a whole item as the view renders it. To
design an item yourself, put `View field` components inside the repeater
instead (one per field, in a Row, Column or Container) and each repeated item
shows its own value. A `View field` has the field to show, the element that
wraps it (a heading level, paragraph, span, div or none) and whether to show
the field's label. The view's own formatter does the output, so an image field
is an image. It needs a view whose Format uses the **Fields** row style, as
only those have individual fields, and it shows nothing when the field is
empty. Choosing a view under Page data, Preview with view, lets you pick the
field from a list. Outside a repeater it shows the first item's value.

**Result summary.** `View result summary` shows a line such as "Displaying 1
- 10 of 54". Its **Format** is the text, using the same tokens as the Views
"Result summary" area (`@start`, `@end`, `@total`, `@label`, `@per_page`,
`@current_page`, `@current_record_count` and `@page_count`), and **Element**
is what wraps it. It shows nothing when the view has no results.

**Card grids** need no setting: the repeater adds no markup of its own,
so each repeated item becomes a direct child of whatever holds the
repeater. Put the repeater in a Row and a Column (span 4 for three
across) in its slot, and the Row's gap and the Column's span control the
grid.

A `View repeater`'s **page condition** ("Show on") gates the whole
repeater on or off by pager page number, independent of its
limit/offset — this is for layouts that need genuinely different
behavior across pagination (e.g. featured content that should only ever
appear on page 1), not just a different slice of the same page's items.


**Previewing with real content.** In the editor's **Page data** panel,
**Preview with view** picks a View display. The full-width preview (the eye
toggle) then renders the template with that view's real content, wrapped in
that display's page template, and its exposed filters and pager work in
place. Editing keeps the placeholders so components stay selectable. The
choice is per user and is not saved on the template. The select lists the
displays that use the template first and starts on one of them, and the
full-width preview says so when no view is chosen. The preview loads only
the stylesheets and scripts its new content needs and applies new
`drupalSettings`. It re-fetches on filter submits, including Views with AJAX
turned on, and on pager and sort links. Other links in it are left to Canvas.

**The View templates panel.** Creating, editing, duplicating and deleting
templates there needs the `administer view templates` permission. Each row shows which Views use the template
("Used by Articles: Page and 2 more"), or "Not used". The row's menu has
**Duplicate**, which copies a template under a new label and machine name and
opens the copy. The copy starts from what the editor shows, including changes
that are not published yet, and starts unused: Views keep using the original
until you choose the copy in their Format settings. A copy whose unpublished
changes are invalid (for example two `View rows`) is refused with the reason.
Rows show "Usage unknown" when the view usage could not be loaded.

**Deleting a template.** A template that a View display uses cannot be
deleted. The View templates panel explains which displays use it and asks you
to choose another template in their Format settings first, and Canvas's API
refuses the deletion too (403, with the reason shown only to users who
hold the `administer view templates` permission). The lock covers deletes that check access (the
panel and Canvas's API); a config import or code calling `delete()` is not
stopped. The lock is a check on the displays' Format
settings, not a configuration dependency: a View does not depend on its view
template in configuration, because Drupal would then delete the whole View
along with the template. A template that is disabled still leaves the View
rendering without it.


## Keeping the panel in step with Canvas

The View templates panel is plain JavaScript and CSS in `extension/`, styled to
match Canvas's own panels instead of bundling Radix Themes. Its sizes, colors
and the row menu's hover were taken from Canvas's code and compiled stylesheet
(its menus are the Radix Themes dropdown at the default size, accent blue), and
were last compared against Canvas 1.13.0. After a Canvas update, compare the
values in `extension/dialog.css` and the top of `extension/app.css` with the
`rt-BaseMenu*` rules in Canvas's compiled stylesheet (`ui/dist/assets/index.css`
in the Canvas module) and with `ui/src/components/UnifiedMenu.tsx`. The
browser tests check the menu's measured sizes, so a drift in this module's own
values shows up there. A change in Canvas itself shows up in
`CanvasCopiesTest`, which pins a fingerprint of the `.rt-BaseMenu*` rules (it is
skipped when Canvas's `ui/dist` is not built). It also pins one of
`ComponentInstanceForm::buildForm()`. When it fails, compare the copy with
Canvas, change the copy if needed, then put the new fingerprint and Canvas
version in the test.

## Coupling to Canvas

Canvas has no stable public API for what this module does, so it uses internals
and a few routes by name. A Canvas update can break any row below. The last
column names the test that fails when it does, or says "none" where there is
no test.

| Canvas symbol | Used in | What breaks | Caught by |
| --- | --- | --- | --- |
| `ComponentSourceBase` (`@internal`), `ComponentSourceInterface`, `ComponentSourceWithSlotsInterface`, the `ComponentSource` attribute | `src/Plugin/Canvas/ComponentSource/*`, base class `ViewTemplateComponentSourceBase` | Fatal errors when a method signature changes; components stop loading | `ViewTemplateComponentContractTest`, and most kernel tests (a fatal fails the suite) |
| `canvas.component.*` schema, `source` choice and `canvas_dev_mode` | `config/install/canvas.component.*.yml` | Config validation reports all 16 components as invalid (see Requirements); a Canvas that validates on save or import could refuse them | `ViewTemplateWithoutDevModeTest`; `ViewTemplateConfigDriftTest` with dev mode |
| Component version hash (`generateVersionHash()`), `Component::loadVersion()`, `getActiveVersion()` | `active_version` in the shipped config, `ViewTemplateComponentInstanceForm` | Components fail to load or edit; stored hashes go stale | `ViewTemplateComponentContractTest`, `ViewTemplateConfigDriftTest`, `ViewTemplateTest::testShippedComponentVersionHashesAreCurrent` |
| `Folder` config entity, created by `Component::postSave()` | `canvas_view_template.install`, `ViewTemplatePlaceholder::LIBRARY_FOLDER_NAME` | Components appear outside the "View template" folder, or two folders exist | `ViewTemplateConfigDriftTest` (folder contents); config sync between environments: none |
| `ComponentTreeConfigEntityBase` (`@internal`), `CanvasHttpApiEligibleConfigEntityInterface`, `ClientServerConversionTrait` (`@internal`), `ClientSideRepresentation`, `EmptyTargetEntityProviderInterface`, `ComponentTreeItemList` | `src/Entity/ViewTemplate.php`, `ViewTemplateTreeItems` | Fatals; the editor cannot load, save or validate templates | `ViewTemplateTest`, `ViewTemplateTreeFuzzTest`; Canvas's HTTP config API (PATCH, POST): none |
| `ComponentTreeItemList::renderify()` and Fibers | `ViewTemplateRenderer` | Placeholders silently render nothing | `ViewTemplateRendererTest`, `ViewTemplateRendererEdgeCasesTest` |
| `CanvasConfigEntityAccessControlHandler` | `ViewTemplateAccessControlHandler` | Access and delete-lock behaviour changes | `ViewTemplateRouteAccessTest`, `ViewTemplatePanelBackendTest` |
| `AutoSaveManager` | `ViewTemplateDrafts` | Previews show the published template instead of the draft | `ViewTemplateDraftsTest` |
| `ComponentInstanceForm` (`final`, its `FORM_ID` and a copy of `buildForm()`), `ComponentTreeLoader`, `ComponentSourceManager`, `JsonSchemaPropsComponentSourceBase`, `Fallback`, `FormIdPreRender` | `ViewTemplateComponentInstanceForm` | The settings form for components in a template errors or differs from Canvas's | `ViewTemplateComponentInstanceFormTest`; drift of the copied `buildForm()`: `CanvasCopiesTest` |
| `ModuleHooks::PAGE_DATA_FORM_ID`, `FormIdPreRender` | `ViewTemplatePreviewForm` | The "Page data" panel loses its AJAX handling | `ViewTemplatePreviewTest` |
| `canvas.api.form.content_entity` route and its controller | `ViewTemplateEntityFormControllerSubscriber` | A change to Canvas's "Page data" panel is not picked up for view templates | none directly (`ViewTemplatePreviewTest` covers the form) |
| `PreviewEnvelope` public properties, `PreviewEnvelopeViewSubscriber` at priority 0 | `ViewTemplatePreviewSubscriber`, `ViewTemplateEntityFormFieldsSubscriber` | The editor preview for view templates fails or loses fields | `ViewTemplatePreviewTest`, `ViewTemplateHardeningTest` |
| Routes `canvas.api.config.{get,patch,delete,post,list}` and the `(a\|b)` form of their `canvas_config_entity_type_id` requirement | `ViewTemplateRouteSubscriber` | View templates are unreachable through Canvas's API, so the editor fails to load them | `ViewTemplateRouteSubscriberTest` |
| Route-name prefix `canvas.api.form.` and the `canvas_stark` theme | `canvas_view_template.routing.yml`, `ViewTemplateComponentInstanceForm` | The settings form renders in the wrong theme or blank | `ViewTemplateComponentInstanceFormTest` (theme), `ViewTemplateRouteAccessTest` (route) |
| Route-name prefix `canvas.api.layout.` | `ViewTemplatePreviewHooks`, `ViewTemplatePreviewSubscriber` | Editor previews and the Views preview behave like front-end requests | `ViewTemplatePreviewTest`, `ViewTemplatePageVariantSelectorSubscriberTest` |
| `CanvasPageVariant` plugin ID with `PREVIEW_KEY` and `VARIANT_ID_KEY`, the `PageVariant` entity | `ViewTemplatePageVariantSelectorSubscriber` | The wrong page template (or none) wraps the view | `ViewTemplatePageVariantSelectorSubscriberTest` |
| Routes `canvas.boot.entity` and `canvas.boot.app` | `ViewTemplateStyle` (links in the Views UI) | Broken "edit template" links in the Views UI | `ViewTemplateStyleTest` |
| Canvas page extensions (`canvas_view_template.canvas_extension.yml`) and the compiled Canvas stylesheet | `extension/`, see "Keeping the panel in step with Canvas" | The View templates panel is missing or looks different | `ViewTemplateExtensionTest` and the browser tests; a change to the menu rules in Canvas's stylesheet: `CanvasCopiesTest` |

## Known limitations

- **View templates don't appear in Canvas's own "Templates" sidebar
  panel**, alongside content templates and page templates. That panel
  is hardcoded in Canvas's own compiled UI bundle to exactly those two
  entity types, with no extension point for a third. View templates get
  their own **View templates** tool in the editor's left rail instead
  (a Canvas page extension, `canvas_view_template.canvas_extension.yml`),
  styled to match, which lists, creates and deletes them and opens
  Canvas's real, generic editor for the actual visual building. There is
  no separate Drupal admin listing.
- **This module extends Canvas classes marked `@internal`**, because Canvas does
  not yet expose a public, stable API for third-party component sources or
  component-tree-bearing entity types. A Canvas release could change them
  without a deprecation cycle; the version constraint in the info file and the
  table above are the safeguards, and this is a known, accepted trade-off.
- **The component settings form is a copy of Canvas's.** Canvas's
  `ComponentInstanceForm` asserts a fixed list of entity types that
  doesn't include `ViewTemplate`, and the class is `final`. View
  templates therefore use their own copy
  (`src/Form/ViewTemplateComponentInstanceForm.php`, routed by
  `canvas.api.form.component_instance.canvas_view_template`), which must be
  kept in sync with Canvas when `ComponentInstanceForm` changes (`CanvasCopiesTest`
  fails when it does). It was last compared against Canvas 1.13.0.

### Not built: linking Canvas components to an item's fields

Components inside a `View repeater` can't have their settings linked to the
current item's fields (a Heading's text to the title, an Image's source to the
image field). Use `View field` for a field's formatted output, `View item` for
a whole item, or `View item link` to link a group of components to the item's
page.

Canvas supports that kind of linking only in content templates, where there is
one host entity. Doing it here would mean making each repeated item act as
that host in turn, which depends on Canvas's internals for evaluating field
links and could break on a Canvas update. A view's items are also not always
entities (files, users, plain database tables), so some fields would never
link. `View field`, `View item` and `View item link` cover most layouts, so
this was left out.

**Also left out: a raw-value option on `View field`.** It would print a field's
stored value as plain text instead of its formatted markup. Its main use would
be feeding a value into another component's setting, which needs the field
linking above. On its own it falls short: an image's raw value is a file ID and
a term reference's is a term ID, a link field's is a bare URL, and a date's is a
timestamp. A view's own field settings (formatter, rewrite results, strip tags)
already control the formatted output that `View field` shows. If plain text in a
markup-free spot is ever needed, a "strip markup" setting would be the safer
version, built when there is a real case.

## Changing a component

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

## Testing

Kernel tests (fast, no browser):

```bash
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/Kernel
```

Browser tests drive the Canvas editor in a real browser: the view select in
Page data, the full-width preview and its pager, the nested repeater
warning, and the View templates panel (usage line, Duplicate, the delete lock,
keyboard focus and menu placement). They need a WebDriver browser. With DDEV's selenium-standalone-chrome
add-on the environment is already set up:

```bash
ddev exec "vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/FunctionalJavascript"
```

The two scripts (`js/live-preview.js`, `js/style-options.js`) also have
dependency-free Node tests against a small DOM stub. They need only Node:

```bash
node tests/js/live-preview.test.js
node tests/js/style-options.test.js
```

### Development checks

The drupal.org pipeline (`.gitlab-ci.yml`) runs PHPCS, PHPStan, cspell and the
PHPUnit tests. The same checks run locally:

```bash
# Coding standards (Drupal and DrupalPractice, see phpcs.xml.dist)
vendor/bin/phpcs --standard=web/modules/custom/canvas_view_template/phpcs.xml.dist web/modules/custom/canvas_view_template

# Static analysis: level 6, with the findings that exist today in
# phpstan-baseline.neon so that only new ones fail
vendor/bin/phpstan analyze -c web/modules/custom/canvas_view_template/phpstan.neon
```

The spell check reads `.cspell-project-words.txt`; add project-specific words
there, one per line.

### Continuous integration

`.gitlab-ci.yml` uses the drupal.org GitLab templates (phpcs, phpstan, PHPUnit
with the kernel and browser tests, and the lint jobs) on the current and next
minor core. It adds one job, `node tests`, which runs the four Node tests on
Node 24 and fails the pipeline. `.eslintrc.json` tells the templates' eslint job
that `extension/*.js` are ES modules and `tests/js` runs in Node; without it
core's config reads them as scripts and reports a parse error on `import`.
`CanvasCopiesTest` compares against the Canvas that `composer` installs there.
