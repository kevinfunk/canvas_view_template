# Canvas View Template

A Drupal module that renders Views displays through Canvas "view templates".
It has its own config entity (`canvas_view_template`) and 16 Canvas library
components whose sources are not discovered (`discovery: FALSE`). The README
covers use and installation, and `docs/` holds the design notes. This file
holds what to know before changing code.

The module lives at `web/modules/custom/canvas_view_template` in a DDEV project.
Run every command below from the project root (`drupal-testing`).

## Commands

```bash
ddev mutagen sync                       # after edits, before running anything
ddev drush cr

ddev exec vendor/bin/phpcs --standard=web/modules/custom/canvas_view_template/phpcs.xml.dist web/modules/custom/canvas_view_template
ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/Kernel
ddev exec "vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/canvas_view_template/tests/src/FunctionalJavascript"
node web/modules/custom/canvas_view_template/tests/js/live-preview.test.js
node web/modules/custom/canvas_view_template/tests/js/style-options.test.js
node web/modules/custom/canvas_view_template/tests/js/panel.test.mjs
node web/modules/custom/canvas_view_template/tests/js/panel-smoke.test.mjs
```

- The full kernel suite takes 10 to 15 minutes. Run it in the background,
  write its output to a file, and read the file when it finishes. Do not run
  two copies at once.
- One check that no test covers: every component's stored hash must equal the
  one Canvas computes.

  ```bash
  ddev drush ev 'foreach (\Drupal\canvas\Entity\Component::loadMultiple() as $c) { $s = $c->getComponentSource(); if (!str_starts_with($s->getPluginId(), "canvas_view_template")) continue; $n = ($n ?? 0) + 1; if ($c->getActiveVersion() !== $s->generateVersionHash()) { echo $c->id() . " mismatch\n"; } } echo "checked " . ($n ?? 0) . "\n";'
  ```

  It must print `checked 16` and no mismatches.

## Rules

- Do not commit or push unless asked.
- Comments are short and only for a non-obvious "why". No comments that restate
  the code, and no long explanations of how something works.
- Slot titles, descriptions and labels in component definitions stay plain
  English. They feed the version hash; translating them would make the hash
  depend on the interface language.
- Do not make the module depend on `canvas_dev_mode`. It is a development
  module. `ViewTemplateSchemaHooks` adds this module's sources to Canvas's
  schema list instead, and `ViewTemplateWithoutDevModeTest` checks the module
  without it, under strict schema checking. A new component source needs its ID
  in that hook; a test checks it.
- Rename with care. The entity type id is `canvas_view_template`, the config
  prefix is `view_template`, the route parameter is `{view_template}`, and the
  style plugin is `canvas_view_template`. They look alike and are different
  things. Find every hit with grep and read it. Never blind-replace.
- `CanvasCopiesTest` fails when Canvas changes code or CSS this module copies.
  That means "compare the copy", not "broken". Compare, then update the
  fingerprint and version in the test.
- Canvas's `@internal` classes are used on purpose; see the coupling table in
  `docs/canvas-coupling.md`. A new use of a Canvas class or route belongs in that table, with
  the test that would catch a break.

## Conventions

- PHP: `declare(strict_types=1)`, hooks as `#[Hook]` classes. The only
  procedural code is `hook_uninstall` and any update hooks in the `.install`
  file.
- Tests use PHPUnit attributes (`#[Group('canvas_view_template')]`,
  `#[DataProvider(...)]`), never `@group` or `@dataProvider` annotations.
  Test method names are lowerCamel with no consecutive capitals. Docblock
  summaries start with a capital letter.
- Every concrete kernel test class carries `#[RunTestsInSeparateProcesses]`.
  PHPUnit does not inherit it from `ViewTemplateKernelTestBase`, and Drupal 12
  throws without it.
- Kernel tests extend `ViewTemplateKernelTestBase`. Use its helpers
  (`treeItem()`, `placeholderItem()`, `renderWithData()`,
  `createPageVariant()`, `installNodeSupport()`) before writing a new builder.
- `render()` takes the template and one `ViewRenderData`. The one collector,
  `ViewRenderDataCollector`, builds it for the front end and for both previews.
- Keep strict config schema on in tests. The exceptions are
  `ViewTemplateWithoutDevModeTest` and `ViewTemplateComponentUpdaterTest`,
  which turn it off on purpose and say why. phpcs flags any other test that
  does.

## The panel's scripts

`extension/app.js` is the list and its actions; `names.js` and `strings.js`
are pure (no DOM) and have Node tests, and `panel-smoke.test.mjs` loads the
whole panel against a stub page; the rest are split by job (see the
header of `app.js`). They load as ES modules, which needs Canvas to frame the
panel with `allow-same-origin` (it does today). Every `t('...')` and `data-t`
needs an entry in `ViewTemplatePanelStringsController::sources()`; a test
checks it.

## Changing a component

See `docs/changing-a-component.md`. In short: the hash changes whenever
slot definitions or the settings schema change; put the new hash in
`config/install`; sites that already have the module need an update hook that
creates the new version and moves placements, using `ComponentUpdater` (the doc
has an example). Update hooks are numbered from 10001. The drift and contract
tests fail when any of these is out of step.

## After a Canvas or Drupal update

Use the `canvas-update-check` skill in `.claude/skills/`.
