# Canvas View Template

Build the layout of a Drupal Views display visually, in Canvas's editor.

## Table of contents

- Introduction
- Requirements
- Recommended modules
- Installation
- Configuration
- Uninstalling
- Troubleshooting
- Known limitations
- More documentation

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

How each component behaves, and how to combine them, is in
[Using the components](docs/using-components.md).

## Requirements

This module requires the following modules:

- [Views](https://www.drupal.org/docs/8/core/modules/views) (Drupal core)
- [Canvas](https://www.drupal.org/project/canvas) 1.13.x, with Drupal 11.4.
  The info file allows only `>=1.13, <1.14`, because this module uses Canvas
  internals that have no stability promise yet (see
  [Coupling to Canvas](docs/canvas-coupling.md)).
  The constraint matches releases only: a git checkout or `1.x-dev` of Canvas
  does not satisfy it, so to test against one, change the constraint in a
  local copy of `canvas_view_template.info.yml`.

`canvas_dev_mode` is not needed, and is not for production. The module's
components pass config validation without it (see
[Component config and Canvas's schema](docs/canvas-coupling.md#component-config-and-canvass-schema)).

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

The components, conditions, previews and the View templates panel are described
in [Using the components](docs/using-components.md).

## Uninstalling

Drupal deletes any View that depends on a module being uninstalled, and a View
depends on the module providing its style. So uninstall is blocked while a
display uses the **Canvas view template** format, with a message listing those
displays. Switch their **Format** to another style first; then uninstalling
deletes the view templates and this module's components, along with the empty
"View template" library folder.

## Troubleshooting

- **"Different UUID" errors on `drush config:import`.** The module was enabled
  on that environment before the import. See
  [Moving between environments](#moving-between-environments-config-sync).
- **The module will not uninstall.** A View display still uses the
  **Canvas view template** format. Switch its **Format** first (see
  [Uninstalling](#uninstalling)).
- **A template will not publish: "View repeater" inside another one.** Move the
  inner repeater beside the first, or use `View item` and `View field` inside
  the first.
- **`View field` shows nothing, or its field list is empty.** It needs a view
  whose Format uses the **Fields** row style. Choose a view under Page data,
  Preview with view, to pick the field from a list.
- **`View header`, `View footer`, `View more link` or `View attachments` show
  nothing.** The view has to have them turned on in its own settings.
- **A template cannot be deleted.** Displays still use it. Choose another
  template in their Format settings first.
- **The module will not install: Canvas version.** It supports only Canvas
  1.13.x.

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
- **It extends Canvas classes marked `@internal`**, and the component settings
  form is a copy of Canvas's. A Canvas release could change either without a
  deprecation cycle, which is why the info file allows only Canvas 1.13.x. See
  [Coupling to Canvas](docs/canvas-coupling.md).
- **No linking of Canvas components to an item's fields.** Use `View field`,
  `View item` or `View item link` instead. The reasoning is in
  [Not built](docs/not-built.md).

## More documentation

- [Using the components](docs/using-components.md): how each component
  behaves and how to combine them.
- [Coupling to Canvas](docs/canvas-coupling.md): the Canvas internals this
  module uses, and what to check after a Canvas update.
- [Changing a component](docs/changing-a-component.md): version hashes and
  update hooks.
- [Testing and CI](docs/testing.md): running the suites and the checks.
- [Not built](docs/not-built.md): features left out, and why.
