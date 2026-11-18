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

For a full list of module features, see the project page.


## Requirements

This module requires the following modules:

- [Views](https://www.drupal.org/docs/8/core/modules/views) (Drupal core)
- [Canvas](https://www.drupal.org/project/canvas)


## Recommended modules

- [View Pager Plugin](https://www.drupal.org/project/view_pager_plugin):
  when a view template uses "View repeater" with a page condition (e.g.
  featured items on page 1, everything else on later pages), the
  standard Views pager always fetches the same number of rows per page.
  View Pager Plugin's "Page Control" pager lets the first page fetch a
  different number of rows than every page after it, so nothing is ever
  dropped or duplicated at the page boundary.


## Installation

Install as you would normally install a contributed Drupal module. See
[Installing Modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.


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

Uninstalling deletes every View that uses the **Canvas view template** format,
with all of its displays (Drupal's confirmation page lists them). To keep those
Views, switch those displays' **Format** to another style first. View templates
and this module's components are deleted too, along with the empty "View
template" library folder.


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
have no items of its own to repeat. The template can't be saved until it is
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

**The View templates panel.** Each row shows which Views use the template
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
refuses the deletion too (403, with the reason shown only to users who may
administer view templates). The lock covers deletes that check access (the
panel and Canvas's API); a config import or code calling `delete()` is not
stopped. The lock is a check on the displays' Format
settings, not a configuration dependency: a View does not depend on its view
template in configuration, because Drupal would then delete the whole View
along with the template. A template that is disabled still leaves the View
rendering without it.


## Keeping the panel in step with Canvas

The View templates panel is plain JavaScript and CSS in `extension/`, styled to
match Canvas's own panels instead of bundling Radix Themes. Its sizes, colours
and the row menu's hover were taken from Canvas's code and compiled stylesheet
(its menus are the Radix Themes dropdown at the default size, accent blue), and
were last compared against Canvas 1.12.0. After a Canvas update, compare the
values in `extension/dialog.css` and the top of `extension/app.css` with the
`rt-BaseMenu*` rules in Canvas's compiled stylesheet (`ui/dist/assets/index.css`
in the Canvas module) and with `ui/src/components/UnifiedMenu.tsx`. The
browser tests check the menu's measured sizes, so a drift in this module's own
values shows up there; a change in Canvas itself does not.

## Coupling to Canvas

What a Canvas update could break, and how it would show:

- **Fiber dispatch.** The renderer relies on
  `ComponentTreeItemList::renderify()` calling each source's
  `renderComponent()` synchronously. If that changes, placeholders silently
  render nothing; the kernel tests catch it.
- **Update hooks** call Canvas APIs (`AutoSaveManager`, `Folder`, `Component`).
  A signature change makes `drush updb` fail with a fatal error.
- **Route regex.** `ViewTemplateRouteSubscriber` assumes Canvas's
  `canvas_config_entity_type_id` requirement has the `(a|b)` form. Another form
  leaves view templates unreachable through Canvas's API, so the editor fails
  to load them.
- **Page data controller.** `ViewTemplateEntityFormControllerSubscriber`
  replaces Canvas's controller for non-fieldable entities with its own form, so
  a change to Canvas's Page data panel is not picked up for view templates.
- **Preview envelope.** `ViewTemplateEntityFormFieldsSubscriber` reads
  `PreviewEnvelope`'s public properties and must run before Canvas's
  `PreviewEnvelopeViewSubscriber` (priority 0). A change shows in the editor,
  for view templates only.
- **`@internal` base classes** (`ComponentSourceBase`,
  `ComponentTreeConfigEntityBase`, `CanvasConfigEntityAccessControlHandler`)
  can change without a deprecation; it would usually show as fatal errors or
  failing kernel tests.

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
- **This module extends a small number of Canvas classes marked
  `@internal`** (`ComponentSourceBase`, `ComponentTreeConfigEntityBase`),
  because Canvas does not yet expose a public, stable API for
  third-party component sources or component-tree-bearing entity types.
  A future Canvas release could change these without a deprecation
  cycle; this is a known, accepted trade-off, not an oversight.
- **The component settings form is a copy of Canvas's.** Canvas's
  `ComponentInstanceForm` asserts a fixed list of entity types that
  doesn't include `ViewTemplate`, and the class is `final`. View
  templates therefore use their own copy
  (`src/Form/ViewTemplateComponentInstanceForm.php`, routed by
  `canvas.api.form.component_instance.view_template`), which must be
  kept in sync with Canvas when `ComponentInstanceForm` changes. It was
  last compared against Canvas 1.12.0.
- **Update migrations cover config component trees only.** When an update
  replaces one of this module's components, placements are moved in view
  templates, patterns, content templates, page templates and page regions
  (published and drafts), not in Canvas Pages or other content entities. A
  moved draft is recorded as saved by the user running the update, at that
  time.

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
schema changes its version hash. It then needs an update hook that creates the
new version on the existing Component entity and makes it active, and the new
hash as `active_version` in the shipped config (`config/install`). Slot titles
and descriptions are plain English for this reason: translated, the hash would
depend on the interface language.

## Testing

Kernel tests (fast, no browser):

```bash
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/Kernel
```

Browser tests drive the Canvas editor in a real browser: the view select in
Page data, the full-width preview and its pager, and the nested repeater
warning. They need a WebDriver browser. With DDEV's selenium-standalone-chrome
add-on the environment is already set up:

```bash
ddev exec "vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/FunctionalJavascript"
```
