# Coupling to Canvas

What the module depends on in Canvas, and how to keep it in step after a
Canvas update. The `canvas-update-check` skill in `.claude/skills/` runs the
checks.

## Component config and Canvas's schema

Canvas's config schema for `canvas.component.*` lists the component sources it
allows, and will not make that a public API until Canvas does
([drupal.org/i/3520484](https://www.drupal.org/i/3520484)). This module adds
its own nine sources to that list with `hook_config_schema_info_alter`
(`src/Hook/ViewTemplateSchemaHooks.php`), the same hook Canvas's
`canvas_dev_mode` uses to remove the whole list. So the 16 shipped components
pass anything that validates config (Config Inspector, a recipe, strict schema
checks) without a development module, and any other source is still refused.

`canvas_dev_mode` is not needed, and is not for production.

If a Canvas update changes the schema so the list is no longer found, the hook
does nothing and validators report `source` as invalid again.
`ViewTemplateWithoutDevModeTest` installs the module under strict schema
checking without `canvas_dev_mode`, so such an update fails a test first.

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

## The coupling table

Canvas has no stable public API for what this module does, so it uses internals
and a few routes by name. A Canvas update can break any row below. The last
column names the test that fails when it does, or says "none" where there is
no test.

| Canvas symbol | Used in | What breaks | Caught by |
| --- | --- | --- | --- |
| `ComponentSourceBase` (`@internal`), `ComponentSourceInterface`, `ComponentSourceWithSlotsInterface`, the `ComponentSource` attribute | `src/Plugin/Canvas/ComponentSource/*`, base class `ViewTemplateComponentSourceBase` | Fatal errors when a method signature changes; components stop loading | `ViewTemplateComponentContractTest`, and most kernel tests (a fatal fails the suite) |
| `canvas.component.*` schema (its list of allowed `source` plugin IDs) and `hook_config_schema_info_alter` | `src/Hook/ViewTemplateSchemaHooks.php`, `config/install/canvas.component.*.yml` | Config validation reports all 16 components as invalid, because the list is no longer found and nothing is added to it | `ViewTemplateWithoutDevModeTest`, `ViewTemplateSchemaHooksTest`, `ViewTemplateConfigDriftTest` |
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

## Accepted trade-offs

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
