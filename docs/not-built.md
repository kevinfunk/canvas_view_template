# Not built

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
