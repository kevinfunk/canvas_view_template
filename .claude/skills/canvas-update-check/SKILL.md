---
name: canvas-update-check
description: Check that canvas_view_template still works after Canvas or Drupal core is updated, and refresh the version stamps. Use when the user says Canvas (or core) was updated, or before widening the Canvas version bound in the info file.
---

# Canvas update check

Canvas has no stable API and this module uses several `@internal` parts of it.
After any Canvas or core update, check each coupling point, then move the
version stamps. Work from the project root (`drupal-testing`); `CLAUDE.md` has
the exact commands.

Do not skip a step because the suites pass: the suites do not cover the form
copy or the menu CSS.

## 1. Before the user updates

- Read the release notes (`https://www.drupal.org/project/canvas/releases/<version>`)
  and match every item against the coupling table in `docs/canvas-coupling.md`. List what
  could be affected. Release notes are often thin, so treat silence as "not
  known", not "safe".
- Offer a snapshot: `ddev snapshot --name before-canvas-<version>`.
- The info file bounds Canvas (`canvas:canvas (>=1.13, <1.14)`), and
  `composer.json` pins `~1.13.0`, so Composer will refuse a newer minor until
  the bound is widened. If it does, tell the user and widen the bound only
  after step 7.
- Do not run suites while the user is updating.

## 2. After the update

```bash
ddev composer show drupal/canvas | grep versions
ddev drush status | grep -i "drupal version"
ddev drush updb --no-interaction
ddev drush cr
```

Then run the hash check from `CLAUDE.md`. It must print `checked 16` and no
mismatch. A mismatch means Canvas changed how it computes the hash: do not
edit the config to match until you know why.

## 3. Run the suites

Run the full kernel suite and the browser suite in the background, each
writing to a file in the scratchpad, plus both Node stubs and phpcs. The
commands are in `CLAUDE.md`. Compare against the last known numbers (kernel
326 tests, browser 14); a drop in count is a finding. New deprecations from
Canvas are worth noting. Ones in this module's files are fixes.

## 4. Compare the component form copy

`src/Form/ViewTemplateComponentInstanceForm.php` is a fork of
`web/modules/contrib/canvas/src/Form/ComponentInstanceForm.php::buildForm()`.
Read both. Everything should match except entity-type handling, which is
Canvas's own types (and the preview entity) against our `ViewTemplate` with
`createEmptyTargetEntity()`. Look for new hidden fields, new request
parameters, changed fallbacks for broken components, changed form attributes
and a changed `FORM_ID`. Port anything that is new.

## 5. Compare the menu CSS

```bash
grep -o "\.rt-BaseMenuContent:where(\.rt-r-size-2){[^}]*}" web/modules/contrib/canvas/ui/dist/assets/index.css
grep -o "\.rt-BaseMenu\(Item\|Separator\|Viewport\)[^{]*{[^}]*}" web/modules/contrib/canvas/ui/dist/assets/index.css
```

Check the numbers against `extension/dialog.css` (`.cvt-menu*`): content
padding, item padding and height, radii, separator margins, highlight colors
(`--accent-9`, `--red-9`). The browser test pins the sizes with a 1px
tolerance, so a pass there confirms the sizes but not the colors.

## 6. Check the places Canvas could change quietly

- `ViewTemplateWithoutDevModeTest` passes: the schema's `source` choice still
  reports exactly the 16 components and nothing else. If Canvas lifted that
  constraint (drupal.org issue 3520484), the test fails with a clear reason:
  update it and the "Component config and Canvas's schema" section of `docs/canvas-coupling.md`.
- Look at the editor by hand: open a view template, add a "View repeater",
  open the View templates panel, open the full-width preview and use the pager
  and an exposed filter. Release notes that mention the preview, iframe, router
  or panel mean this is not optional.

## 7. Move the stamps

Only for what was actually checked. Replace the old version in:

- `README.md`: the Requirements Canvas version.
- `docs/canvas-coupling.md`: the panel paragraph that says the CSS was "last
  compared against", and the bullet that says the same of the form copy.
- `extension/app.css` (line 4) and `extension/dialog.css` (two places).
- `src/Form/ViewTemplateComponentInstanceForm.php` (class docblock).
- The coupling table rows, if a symbol was added, removed or renamed.
- `canvas_view_template.info.yml` and `composer.json`, widening the Canvas
  bound only when steps 2 to 6 are clean.

A stamp for something you did not compare stays at the old version, and the
report says so.

## 8. Report

One short list: versions before and after, hash result, suite counts, what
the form and CSS comparisons found, the by-hand check (done, or not done and
why), stamps moved, and anything left open. Do not commit unless asked.
