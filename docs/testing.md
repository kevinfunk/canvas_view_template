# Testing and CI

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

## Development checks

The drupal.org pipeline (`.gitlab-ci.yml`) runs PHPCS, PHPStan, cspell and the
PHPUnit tests. The same checks run locally:

```bash
# Coding standards (Drupal and DrupalPractice, see phpcs.xml.dist)
vendor/bin/phpcs --standard=web/modules/custom/canvas_view_template/phpcs.xml.dist web/modules/custom/canvas_view_template

# Static analysis: level 6, with the findings that exist today in
# phpstan-baseline.neon so that only new ones fail
vendor/bin/phpstan analyze -c web/modules/custom/canvas_view_template/phpstan.neon
```

What `phpstan-baseline.neon` holds: 39 entries, 21 in `src/` and 18 in tests.
In `src/`:
- Views types that differ from runtime: `ViewExecutable::$total_rows`, `$filter`,
  `$pager` and `$display_handler`, and `ResultRow::$_entity`, are declared
  non-nullable but can be unset.
- Missing array shapes: `buildOptionsForm()` and `summaryTitle()` in the style
  plugin have no declared types, and each component's
  `getDefaultExplicitInput()` returns a shape wider than Canvas's interface.
- `DependencySerializationTrait` does not support private or read-only
  properties, so the access handler's are flagged.
- Two `\Drupal` calls in `ViewTemplateField` that cannot be injected.
- `ViewTemplateRenderer` and `ViewTemplateUninstallValidator` each have one
  parameter or return type that PHPStan cannot narrow.

In the tests the entries are mostly narrow types in Canvas and core APIs, such
as `AccessResultInterface::getReason()` (it exists only on the forbidden
result) and Canvas's client-model array shape. These are real gaps in typing,
not hidden bugs, but each is a place where a stricter type could be added. A
new finding fails the check, and fixing one means deleting its entry.

The spell check reads `.cspell-project-words.txt`; add project-specific words
there, one per line.

## Continuous integration

`.gitlab-ci.yml` uses the drupal.org GitLab templates (phpcs, phpstan, PHPUnit
with the kernel and browser tests, and the lint jobs) on the current and next
minor core. It adds one job, `node tests`, which runs the four Node tests on
Node 24 and fails the pipeline. `.eslintrc.json` tells the templates' eslint job
that `extension/*.js` are ES modules and `tests/js` runs in Node; without it
core's config reads them as scripts and reports a parse error on `import`.
`CanvasCopiesTest` compares against the Canvas that `composer` installs there.

The timing and memory tests (`ViewTemplateRenderCostTest`,
`ViewTemplateScaleTest`, group `canvas_view_template_slow`) depend on how busy
the runner is. The blocking PHPUnit jobs skip them with `_PHPUNIT_EXTRA`, and
the `phpunit slow` job runs them on current core with `allow_failure`. Locally
they run with the rest of the suite. This setup has not yet run on GitLab; check
that the job name in `extends:` matches the template's.
