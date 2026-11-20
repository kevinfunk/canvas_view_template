/**
 * The "View templates" tool in the Canvas editor.
 *
 * It runs in a sandboxed iframe that cannot navigate the top window, use
 * alert()/confirm(), or submit a <form>. So navigation goes through the
 * parent's router, and dialogs are drawn in the parent page.
 *
 * This file is the list and its actions. The rest is split by job:
 * strings.js (translation), names.js (ids, sorting, filtering; no DOM),
 * api.js, dom.js, host.js (the editor page), menu.js and dialog.js.
 */
import { api, base, getJson, request } from './api.js';
import { showDialog, openDialogs } from './dialog.js';
import { announce, element, els, focusTrigger, showError } from './dom.js';
import { adaptHost, closePanel, openEditor, restoreHost } from './host.js';
import { closeMenu, installMenuDismissal, isMenuOpen, isMenuOpenFor, showMenu } from './menu.js';
import { MACHINE_NAME, freeId, matchesQuery, slug, sortTemplates } from './names.js';
import { getLangcode, setStrings, summarize, t } from './strings.js';

const TEMPLATE_ICON =
  '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linejoin="round"><rect x="2.5" y="1.5" width="10" height="4" rx="0.5"/><rect x="2.5" y="7.5" width="4" height="6" rx="0.5"/><rect x="8.5" y="7.5" width="4" height="2" rx="0.5"/><rect x="8.5" y="11.5" width="4" height="2" rx="0.5"/></svg>';
const DOTS_ICON =
  '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.625 7.5C3.625 8.12132 3.12132 8.625 2.5 8.625C1.87868 8.625 1.375 8.12132 1.375 7.5C1.375 6.87868 1.87868 6.375 2.5 6.375C3.12132 6.375 3.625 6.87868 3.625 7.5ZM8.625 7.5C8.625 8.12132 8.12132 8.625 7.5 8.625C6.87868 8.625 6.375 8.12132 6.375 7.5C6.375 6.87868 6.87868 6.375 7.5 6.375C8.12132 6.375 8.625 6.87868 8.625 7.5ZM12.5 8.625C13.1213 8.625 13.625 8.12132 13.625 7.5C13.625 6.87868 13.1213 6.375 12.5 6.375C11.8787 6.375 11.375 6.87868 11.375 7.5C11.375 8.12132 11.8787 8.625 12.5 8.625Z" fill="currentColor"/></svg>';

els.close.addEventListener('click', closePanel);
adaptHost();
installMenuDismissal();
window.addEventListener('pagehide', () => {
  closeMenu();
  for (const close of [...openDialogs]) {
    close();
  }
  restoreHost();
});

// View display labels per template id; null if the request failed.
let usage = null;
let templateIds = new Set();
const loadUsage = async () => {
  try {
    usage = (await getJson(`${base}/canvas-view-template/usage`)).templates ?? {};
  } catch {
    usage = null;
  }
};

const usageText = (id) => {
  if (!usage) {
    return t('Usage unknown');
  }
  const labels = (usage[id] ?? []).map((display) => display.label);
  return labels.length ? t('Used by @views', { views: summarize(labels) }) : t('Not used');
};

const usedBy = async (template) => {
  try {
    const body = await getJson(`${base}/canvas-view-template/usage/${encodeURIComponent(template.id)}`);
    return body.displays.map((display) => display.label);
  } catch {
    return [];
  }
};

const renderNode = (template) => {
  const name = template.label || template.id;
  const item = element('li', 'node');

  // Title tooltip: long names are truncated.
  const main = element('button', 'node__main', { type: 'button', title: name });
  const icon = element('span', 'node__icon');
  icon.innerHTML = TEMPLATE_ICON;
  const text = element('span', 'node__text');
  text.append(element('span', 'node__title', { textContent: name }));
  if (template.description) {
    text.append(element('span', 'node__desc', { textContent: template.description }));
  }
  text.append(element('span', 'node__usage', { textContent: usageText(template.id) }));
  main.append(icon, text);
  main.addEventListener('click', () => openEditor(template.id));

  const dots = element('button', 'node__dots', { type: 'button' });
  dots.setAttribute('aria-label', t('Actions for @name', { name }));
  dots.setAttribute('aria-haspopup', 'menu');
  dots.setAttribute('aria-expanded', 'false');
  dots.innerHTML = DOTS_ICON;

  const entries = [
    { label: t('Edit'), run: () => openEditor(template.id) },
    { label: t('Duplicate'), run: () => openDuplicateDialog(template, dots) },
    { label: t('Delete'), danger: true, run: () => openDeleteDialog(template, dots) },
  ];
  dots.addEventListener('click', (event) => {
    event.stopPropagation();
    if (isMenuOpenFor(dots)) {
      closeMenu({ restoreFocus: true });
    } else {
      showMenu(dots, item, name, entries);
    }
  });
  dots.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!isMenuOpenFor(dots)) {
        showMenu(dots, item, name, entries);
      }
    }
  });

  item.append(main, dots);
  return item;
};

let allTemplates = [];

const render = () => {
  const query = els.search.value.trim().toLowerCase();
  const shown = allTemplates.filter((template) => matchesQuery(template, query));
  els.list.replaceChildren(...shown.map(renderNode));
  els.loading.hidden = true;
  els.empty.hidden = allTemplates.length > 0;
  els.noMatch.hidden = shown.length > 0 || allTemplates.length === 0;
  if (query && !els.noMatch.hidden) {
    announce(els.noMatch.textContent);
  }
};
els.search.addEventListener('input', render);

let loading = false;
let lastSignature = '';
const load = async ({ announceCount = true, background = false } = {}) => {
  if (!background) {
    showError('');
  }
  loading = true;
  try {
    const templates = sortTemplates(Object.values(await getJson(api)), getLangcode());
    templateIds = new Set(templates.map((template) => template.id));
    await loadUsage();
    // Unchanged data keeps the rows, and with them focus.
    const signature = JSON.stringify([templates, usage]);
    const unchanged = !announceCount && signature === lastSignature;
    lastSignature = signature;
    allTemplates = templates;
    if (unchanged) {
      return;
    }
    render();
    els.toolbar.hidden = false;
    if (announceCount) {
      announce(templates.length === 1 ? t('1 view template.') : t('@count view templates.', { count: templates.length }));
    }
  } catch (error) {
    if (background) {
      // Keep the list that is shown; the next refresh tries again.
      return;
    }
    els.loading.hidden = true;
    els.toolbar.hidden = true;
    showError(t('Could not load view templates: @error', { error: error.message }));
  } finally {
    loading = false;
  }
};

// Another tab may have changed the list: refresh quietly when this one is
// used again, unless a menu or dialog is open or a load is running.
const refreshIfIdle = () => {
  if (!loading && !isMenuOpen() && !openDialogs.size && !els.toolbar.hidden && !document.hidden) {
    load({ announceCount: false, background: true });
  }
};
document.addEventListener('visibilitychange', refreshIfIdle);
els.retry.addEventListener('click', () => {
  els.loading.hidden = false;
  load();
});

// Stands in for confirm(), which the sandbox blocks.
let deleteOpening = false;
const openDeleteDialog = async (template, trigger) => {
  closeMenu();
  if (deleteOpening || openDialogs.size) {
    return;
  }
  deleteOpening = true;
  let views;
  try {
    views = await usedBy(template);
  } finally {
    deleteOpening = false;
  }
  if (openDialogs.size) {
    return;
  }
  const name = template.label || template.id;
  if (views.length) {
    showDialog({
      title: t('Template is in use'),
      message:
        views.length === 1
          ? t('"@name" is used by @views. Choose another template in the Format settings of that view first, then delete it.', { name, views: summarize(views) })
          : t('"@name" is used by @views. Choose another template in the Format settings of those views first, then delete it.', { name, views: summarize(views) }),
      confirmLabel: t('OK'),
      confirmClass: 'solid',
      cancelClass: 'outline',
      hideCancel: true,
      returnFocus: () => focusTrigger(trigger),
      onConfirm: async () => {},
    });
    return;
  }
  showDialog({
    title: t('Delete template'),
    message: t('Are you sure you want to delete "@name"? This action cannot be undone.', { name }),
    confirmLabel: t('Delete'),
    confirmClass: 'danger',
    cancelClass: 'outline',
    focus: 'cancel',
    returnFocus: () => focusTrigger(trigger),
    onConfirm: async () => {
      try {
        await request(`${api}/${encodeURIComponent(template.id)}`, 'DELETE');
      } catch (failure) {
        throw new Error(t('Could not delete "@name": @error', { name, error: failure.message }));
      }
      await load({ announceCount: false });
      announce(t('Deleted "@name".', { name }));
    },
  });
};

const copyIdFor = (label) => freeId(label, new Set([...templateIds, ...Object.keys(usage ?? {})]));

/**
 * The label + machine name dialog shared by Create and Duplicate.
 *
 * `suggestId` derives the machine name from the label until it is edited by
 * hand; `body(id, label, values)` is posted to `url`; `verb` is `create` or
 * `duplicate`, to word a failure.
 */
const openNameDialog = ({ title, message, fields, confirmLabel, trigger, suggestId, url, verb, body }) => {
  closeMenu();
  let idEdited = false;
  const invalid = (text, field) => Object.assign(new Error(text), { field });
  showDialog({
    title,
    message,
    fields,
    confirmLabel,
    confirmClass: 'solid',
    cancelClass: 'gray',
    focus: 'label',
    returnFocus: trigger ? () => focusTrigger(trigger) : undefined,
    onInput: (key, value, inputs) => {
      if (key === 'id') {
        idEdited = true;
      } else if (key === 'label' && !idEdited) {
        inputs.id.value = suggestId(value);
      }
    },
    onConfirm: async (values) => {
      const id = values.id.trim();
      const label = values.label.trim();
      if (label === '') {
        throw invalid(t('Enter a label.'), 'label');
      }
      if (!MACHINE_NAME.test(id)) {
        throw invalid(t('The machine name must start with a letter and use only lowercase letters, numbers and underscores.'), 'id');
      }
      try {
        await request(url, 'POST', body(id, label, values));
      } catch (failure) {
        throw failure.status === 409
          ? invalid(t('A view template with the machine name "@id" already exists.', { id }), 'id')
          : new Error(
              verb === 'create'
                ? t('Could not create the view template: @error', { error: failure.message })
                : t('Could not duplicate the view template: @error', { error: failure.message }),
            );
      }
      openEditor(id);
    },
  });
};

const openDuplicateDialog = (template, trigger) => {
  const label = t('Copy of @label', { label: template.label || template.id });
  openNameDialog({
    title: t('Duplicate view template'),
    message: t('Starts from what the editor shows now, including changes not yet published. Views keep using the original until you choose the copy in their Format settings.'),
    fields: [
      { key: 'label', label: t('Label'), value: label },
      { key: 'id', label: t('Machine name'), value: copyIdFor(label) },
    ],
    confirmLabel: t('Duplicate and edit'),
    trigger,
    suggestId: copyIdFor,
    url: `${base}/canvas-view-template/duplicate/${encodeURIComponent(template.id)}`,
    verb: 'duplicate',
    body: (id, copyLabel) => ({ id, label: copyLabel }),
  });
};

const openCreateDialog = () => {
  openNameDialog({
    title: t('New view template'),
    fields: [
      { key: 'label', label: t('Label') },
      { key: 'id', label: t('Machine name') },
      { key: 'description', label: t('Description'), optional: true },
    ],
    confirmLabel: t('Create and edit'),
    suggestId: (value) => (value.trim() ? slug(value) : ''),
    url: api,
    verb: 'create',
    body: (id, label, values) => {
      const description = values.description.trim();
      return description === '' ? { id, label } : { id, label, description };
    },
  });
};
els.newButton.addEventListener('click', openCreateDialog);

// Translates the page's own text, then loads the list. Without the table
// (the request failed) the English text stays.
const start = async () => {
  try {
    const table = await getJson(`${base}/canvas-view-template/panel-strings`);
    setStrings(table.strings, table.langcode);
    document.documentElement.lang = getLangcode();
    document.documentElement.dir = table.dir || 'ltr';
  } catch {
    // Keep English.
  }
  document.title = t('View templates');
  for (const node of document.querySelectorAll('[data-t]')) {
    node.textContent = t(node.dataset.t);
  }
  for (const node of document.querySelectorAll('[data-t-aria-label]')) {
    node.setAttribute('aria-label', t(node.dataset.tAriaLabel));
  }
  els.search.placeholder = t('Search…');
  load();
};
start();

start();
