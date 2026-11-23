# Using the components

How the view template components behave, and how to combine them. For the
steps to create and use a template, see the [README](../README.md#configuration).

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
