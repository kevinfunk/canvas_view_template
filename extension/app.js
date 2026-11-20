/**
 * The "View templates" tool in the Canvas editor.
 *
 * It runs in a sandboxed iframe that cannot navigate the top window, use
 * alert()/confirm(), or submit a <form>. So navigation goes through the
 * parent's router, and dialogs are drawn in the parent page.
 */
(() => {
  const scriptUrl = document.currentScript?.src;

  const base = window.location.pathname.split('/modules/')[0];
  const api = `${base}/canvas/api/v0/config/canvas_view_template`;

  // Same rule as the server's.
  const MACHINE_NAME = /^[a-z][a-z0-9_]{0,63}$/;
  const MACHINE_NAME_MAX = 64;
  // Leaves room for a `_NN` suffix on a copy's id.
  const COPY_BASE_MAX = MACHINE_NAME_MAX - 4;
  const SUMMARY_MAX = 3;

  const TEMPLATE_ICON =
    '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linejoin="round"><rect x="2.5" y="1.5" width="10" height="4" rx="0.5"/><rect x="2.5" y="7.5" width="4" height="6" rx="0.5"/><rect x="8.5" y="7.5" width="4" height="2" rx="0.5"/><rect x="8.5" y="11.5" width="4" height="2" rx="0.5"/></svg>';
  const DOTS_ICON =
    '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.625 7.5C3.625 8.12132 3.12132 8.625 2.5 8.625C1.87868 8.625 1.375 8.12132 1.375 7.5C1.375 6.87868 1.87868 6.375 2.5 6.375C3.12132 6.375 3.625 6.87868 3.625 7.5ZM8.625 7.5C8.625 8.12132 8.12132 8.625 7.5 8.625C6.87868 8.625 6.375 8.12132 6.375 7.5C6.375 6.87868 6.87868 6.375 7.5 6.375C8.12132 6.375 8.625 6.87868 8.625 7.5ZM12.5 8.625C13.1213 8.625 13.625 8.12132 13.625 7.5C13.625 6.87868 13.1213 6.375 12.5 6.375C11.8787 6.375 11.375 6.87868 11.375 7.5C11.375 8.12132 11.8787 8.625 12.5 8.625Z" fill="currentColor"/></svg>';

  const els = {
    list: document.getElementById('list'),
    loading: document.getElementById('loading'),
    empty: document.getElementById('empty'),
    noMatch: document.getElementById('no-match'),
    search: document.getElementById('search'),
    error: document.getElementById('error'),
    toolbar: document.getElementById('toolbar'),
    newButton: document.getElementById('new-button'),
    errorBox: document.getElementById('error-box'),
    retry: document.getElementById('retry-button'),
    status: document.getElementById('status'),
    close: document.getElementById('close-button'),
  };

  // Translated by the server, keyed by the English text; `@name` marks a
  // placeholder. Without the table the English text is shown.
  let strings = {};
  let langcode = document.documentElement.lang || 'en';
  const t = (source, args = {}) =>
    (strings[source] ?? source).replace(/@(\w+)/g, (match, name) => (name in args ? String(args[name]) : match));

  const element = (tag, className, props = {}, doc = document) => {
    const node = doc.createElement(tag);
    if (className) {
      node.className = className;
    }
    return Object.assign(node, props);
  };

  /** The document that hosts dialogs: the editor page when framed. */
  const hostDocument = (() => {
    try {
      const doc = window.parent.document;
      return doc.body ? doc : document;
    } catch {
      return document;
    }
  })();

  const check = async (response) => {
    if (response.ok) {
      return response;
    }
    let detail = '';
    try {
      const body = await response.json();
      detail = body.errors?.map((e) => e.detail).join(' ') || body.message || '';
    } catch {
      // Not JSON; fall back to the status line.
    }
    const failure = new Error(detail || `${response.status} ${response.statusText}`);
    failure.status = response.status;
    throw failure;
  };

  const getJson = async (url) => {
    const response = await check(
      await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
    );
    return response.json();
  };

  const request = async (url, method, body) => {
    const token = await (await fetch(`${base}/session/token`, { credentials: 'same-origin' })).text();
    const headers = { 'X-CSRF-Token': token, Accept: 'application/json' };
    if (body) {
      headers['Content-Type'] = 'application/json';
    }
    await check(
      await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: body ? JSON.stringify(body) : undefined,
      }),
    );
  };

  const list = (items) => {
    try {
      return new Intl.ListFormat(langcode, { style: 'long', type: 'conjunction' }).format(items);
    } catch {
      return items.join(', ');
    }
  };

  const summarize = (labels) => {
    const shown = labels.slice(0, SUMMARY_MAX);
    const more = labels.length - shown.length;
    return list(more > 0 ? [...shown, t('@count more', { count: more })] : shown);
  };

  const slug = (label) =>
    label
      // Drops accents, so "Café" becomes "cafe" rather than "caf".
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      // Machine names must start with a letter.
      .replace(/^(\d)/, 't_$1')
      .slice(0, MACHINE_NAME_MAX) || 'template';

  const showError = (message) => {
    els.error.textContent = message || '';
    els.errorBox.hidden = !message;
  };

  const announce = (message) => {
    els.status.textContent = '';
    setTimeout(() => {
      els.status.textContent = message;
    }, 50);
  };

  const editorUrl = (id) => `${base}/canvas/editor/canvas_view_template/${encodeURIComponent(id)}`;

  const navigateParent = (url) => {
    try {
      const parent = window.parent;
      const index = parent.history.state?.idx ?? 0;
      parent.history.pushState(
        { usr: null, key: Math.random().toString(36).slice(2, 10), idx: index + 1 },
        '',
        url,
      );
      parent.dispatchEvent(new parent.PopStateEvent('popstate', { state: parent.history.state }));
    } catch {
      // Not framed or parent unreachable.
      window.location.assign(url);
    }
  };

  const openEditor = (id) => navigateParent(editorUrl(id));

  const closePanel = () => {
    try {
      const parent = window.parent;
      if ((parent.history.state?.idx ?? 0) > 0) {
        parent.history.back();
        return;
      }
    } catch {
      // Fall through to the start page.
    }
    navigateParent(`${base}/canvas`);
  };
  els.close.addEventListener('click', closePanel);

  /**
   * Makes the host look like a Canvas side panel (no back bar, 296px wide).
   *
   * Does nothing if Canvas's markup is not as expected. `restoreHost` undoes it.
   */
  let restoreHost = () => {};
  const adaptHost = () => {
    try {
      const frame = window.frameElement;
      const page = frame?.parentElement;
      const bar = frame?.previousElementSibling;
      if (!page || !bar?.querySelector('[aria-label="Go back"]')) {
        return;
      }
      bar.style.display = 'none';
      page.style.width = '296px';
      page.style.flex = 'none';
      page.style.borderRight = '1px solid var(--gray-5, #e0e1e6)';
      restoreHost = () => {
        bar.style.display = '';
        page.style.width = '';
        page.style.flex = '';
        page.style.borderRight = '';
      };
    } catch {
      // Not framed or parent unreachable.
    }
  };
  adaptHost();

  let dialogStyles = null;
  let dialogStylesReady = Promise.resolve();
  // Loads the dialog stylesheet into the host page once; the menu must wait for it.
  const ensureDialogStyles = () => {
    if (dialogStyles || !scriptUrl) {
      return dialogStylesReady;
    }
    dialogStyles = hostDocument.createElement('link');
    dialogStyles.rel = 'stylesheet';
    dialogStyles.href = new URL('dialog.css', scriptUrl).href;
    dialogStylesReady = new Promise((resolve) => {
      dialogStyles.onload = resolve;
      dialogStyles.onerror = resolve;
    });
    hostDocument.head.append(dialogStyles);
    return dialogStylesReady;
  };

  const openDialogs = new Set();

  // Focuses a row's "..." button, kept shown until focus leaves it.
  const focusTrigger = (button) => {
    if (!button?.isConnected) {
      els.newButton.focus();
      return;
    }
    const row = button.closest('.node');
    row?.classList.add('node--keep');
    button.addEventListener('blur', () => row?.classList.remove('node--keep'), { once: true });
    button.focus();
  };

  // The row menu is drawn in the editor page so it can hang out of the narrow frame.
  let openMenu = null;
  const onResize = () => closeMenu();
  const closeMenu = ({ restoreFocus = false } = {}) => {
    if (!openMenu) {
      return;
    }
    const { menu, button, row } = openMenu;
    openMenu = null;
    menu.remove();
    button.setAttribute('aria-expanded', 'false');
    row.classList.remove('node--menu-open');
    hostDocument.removeEventListener('mousedown', onHostPointer, true);
    hostDocument.removeEventListener('keydown', onHostKeydown, true);
    window.removeEventListener('resize', onResize);
    if (restoreFocus) {
      focusTrigger(button);
    }
  };
  function onHostPointer(event) {
    if (openMenu && !openMenu.menu.contains(event.target)) {
      closeMenu();
    }
  }
  function onHostKeydown(event) {
    if (event.key === 'Escape' && openMenu) {
      event.stopPropagation();
      closeMenu({ restoreFocus: true });
    }
  }
  document.addEventListener('click', (event) => {
    if (openMenu && !openMenu.button.contains(event.target)) {
      closeMenu();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeMenu({ restoreFocus: true });
    }
  });
  document.addEventListener('scroll', () => closeMenu(), true);
  window.addEventListener('pagehide', () => {
    closeMenu();
    for (const close of [...openDialogs]) {
      close();
    }
    dialogStyles?.remove();
    restoreHost();
  });

  /** Opens a row's menu in the editor page, below its button or above if no room. */
  const showMenu = (button, row, name, entries) => {
    closeMenu();
    const styles = ensureDialogStyles();
    const doc = hostDocument;
    const make = (tag, className, props) => element(tag, className, props, doc);
    const menu = make('div', 'cvt-menu');
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', t('Actions for @name', { name }));
    const separator = () => make('div', 'cvt-menu__separator', { role: 'separator' });
    menu.append(make('div', 'cvt-menu__label', { textContent: name, title: name }));
    menu.lastChild.setAttribute('role', 'presentation');
    const buttons = entries.map((entry) => {
      const item = make('button', `cvt-menu__item${entry.danger ? ' cvt-menu__item--danger' : ''}`, {
        type: 'button',
        textContent: entry.label,
      });
      item.setAttribute('role', 'menuitem');
      item.addEventListener('click', () => {
        closeMenu();
        entry.run();
      });
      return item;
    });
    for (const entryButton of buttons) {
      menu.append(separator(), entryButton);
    }
    menu.addEventListener('keydown', (event) => {
      const index = buttons.indexOf(doc.activeElement);
      const move = (target) => {
        event.preventDefault();
        buttons[(target + buttons.length) % buttons.length].focus();
      };
      if (event.key === 'ArrowDown') {
        move(index + 1);
      } else if (event.key === 'ArrowUp') {
        move(index - 1);
      } else if (event.key === 'Home') {
        move(0);
      } else if (event.key === 'End') {
        move(buttons.length - 1);
      } else if (event.key === 'Tab') {
        closeMenu({ restoreFocus: true });
      }
    });

    // Hidden until the stylesheet has loaded, so it can be measured.
    Object.assign(menu.style, { position: 'fixed', left: '0', top: '0', visibility: 'hidden' });
    doc.body.append(menu);

    button.setAttribute('aria-expanded', 'true');
    row.classList.add('node--menu-open');
    openMenu = { menu, button, row };
    doc.addEventListener('mousedown', onHostPointer, true);
    doc.addEventListener('keydown', onHostKeydown, true);
    window.addEventListener('resize', onResize);

    styles.then(() => {
      if (openMenu?.menu !== menu) {
        return;
      }
      const frameBox = window.frameElement?.getBoundingClientRect() ?? { left: 0, top: 0 };
      const box = button.getBoundingClientRect();
      const view = doc.defaultView;
      const left = Math.max(8, Math.min(frameBox.left + box.left - 4, view.innerWidth - menu.offsetWidth - 8));
      const below = frameBox.top + box.bottom + 4;
      const above = frameBox.top + box.top - menu.offsetHeight - 4;
      const top = below + menu.offsetHeight > view.innerHeight - 8 && above >= 8 ? above : below;
      menu.style.left = `${left}px`;
      menu.style.top = `${top}px`;
      menu.style.visibility = '';
      buttons[0].focus();
    });
  };

  /**
   * Shows a modal dialog in the host page.
   *
   * `onConfirm` rejecting keeps the dialog open and shows the error.
   * `focus` is 'cancel' or a field key; `returnFocus` moves focus on close.
   */
  const showDialog = (options) => {
    ensureDialogStyles();
    const previousFocus = document.activeElement;
    const doc = hostDocument;
    const make = (tag, className, props) => element(tag, className, props, doc);

    const overlay = make('div', 'cvt-overlay');
    const box = make('div', 'cvt-dialog', { role: options.fields ? 'dialog' : 'alertdialog', tabIndex: -1 });
    box.setAttribute('aria-modal', 'true');
    const titleId = `cvt-dialog-title-${Math.random().toString(36).slice(2, 8)}`;
    box.setAttribute('aria-labelledby', titleId);
    box.append(make('h2', 'cvt-dialog__title', { id: titleId, textContent: options.title }));
    if (options.message) {
      const textId = `${titleId}-text`;
      box.setAttribute('aria-describedby', textId);
      box.append(make('p', 'cvt-dialog__text', { id: textId, textContent: options.message }));
    }

    const inputs = {};
    for (const field of options.fields ?? []) {
      const label = make('label', 'cvt-field');
      const caption = make('span', '', { textContent: field.label });
      if (field.optional) {
        caption.append(' ', make('em', '', { textContent: t('(optional)') }));
      }
      const input = make('input', '', { type: 'text', autocomplete: 'off', value: field.value ?? '' });
      input.addEventListener('input', () => options.onInput?.(field.key, input.value, inputs));
      inputs[field.key] = input;
      label.append(caption, input);
      box.append(label);
    }

    const errorId = `${titleId}-error`;
    const error = make('p', 'cvt-error', { id: errorId, hidden: true });
    error.setAttribute('role', 'alert');
    const cancel = make('button', `cvt-button cvt-button--${options.cancelClass}`, { type: 'button', textContent: t('Cancel') });
    const confirm = make('button', `cvt-button cvt-button--${options.confirmClass}`, { type: 'button', textContent: options.confirmLabel });
    box.append(error, make('div', 'cvt-dialog__actions'));
    box.lastChild.append(...(options.hideCancel ? [confirm] : [cancel, confirm]));
    overlay.append(box);

    const dialog = {
      inputs,
      setError(message, fieldKey) {
        error.textContent = message || '';
        error.hidden = !message;
        for (const input of Object.values(inputs)) {
          input.removeAttribute('aria-invalid');
          input.removeAttribute('aria-describedby');
        }
        const field = fieldKey ? inputs[fieldKey] : null;
        if (message && field) {
          field.setAttribute('aria-invalid', 'true');
          field.setAttribute('aria-describedby', errorId);
          field.focus();
        }
      },
    };

    let busy = false;
    // Elements this dialog made inert, so only those are restored.
    const inerted = [];
    const close = ({ force = false } = {}) => {
      if (busy && !force) {
        return;
      }
      doc.removeEventListener('keydown', onKeydown, true);
      doc.removeEventListener('focusin', onFocusin, true);
      overlay.remove();
      openDialogs.delete(forceClose);
      for (const node of inerted.splice(0)) {
        node.inert = false;
      }
      if (options.returnFocus) {
        options.returnFocus();
      } else {
        previousFocus?.focus?.();
      }
    };
    const forceClose = () => close({ force: true });

    const submit = async () => {
      if (busy) {
        return;
      }
      busy = true;
      dialog.setError('');
      // A disabled button drops focus to the page.
      if (doc.activeElement === confirm) {
        box.focus();
      }
      confirm.disabled = true;
      const confirmText = confirm.textContent;
      confirm.textContent = t('Working…');
      box.setAttribute('aria-busy', 'true');
      const values = Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value]));
      try {
        await options.onConfirm(values, dialog);
        busy = false;
        close();
      } catch (failure) {
        busy = false;
        confirm.disabled = false;
        confirm.textContent = confirmText;
        box.removeAttribute('aria-busy');
        dialog.setError(failure.message, failure.field);
      }
    };

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
      }
      if (event.key === 'Enter' && event.target instanceof doc.defaultView.HTMLInputElement) {
        event.preventDefault();
        if (!confirm.disabled) {
          submit();
        }
        return;
      }
      if (event.key === 'Tab') {
        const focusable = [...box.querySelectorAll('input, button:not(:disabled)')];
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!first || !box.contains(doc.activeElement) || doc.activeElement === box) {
          event.preventDefault();
          (first ?? box).focus();
        } else if (event.shiftKey && doc.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    };

    const onFocusin = (event) => {
      if (!box.contains(event.target)) {
        box.focus();
      }
    };

    cancel.addEventListener('click', () => close());
    confirm.addEventListener('click', submit);
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        close();
      }
    });
    doc.addEventListener('keydown', onKeydown, true);
    doc.addEventListener('focusin', onFocusin, true);
    for (const node of doc.body.children) {
      if (!node.inert) {
        node.inert = true;
        inerted.push(node);
      }
    }
    doc.body.append(overlay);
    openDialogs.add(forceClose);
    (options.focus && inputs[options.focus] ? inputs[options.focus] : options.hideCancel ? confirm : cancel).focus();
    return dialog;
  };

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
      if (openMenu?.button === dots) {
        closeMenu({ restoreFocus: true });
      } else {
        showMenu(dots, item, name, entries);
      }
    });
    dots.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (openMenu?.button !== dots) {
          showMenu(dots, item, name, entries);
        }
      }
    });

    item.append(main, dots);
    return item;
  };

  let allTemplates = [];

  const matches = (template, query) =>
    [template.label, template.id, template.description].some((value) => (value ?? '').toLowerCase().includes(query));

  const render = () => {
    const query = els.search.value.trim().toLowerCase();
    const shown = query ? allTemplates.filter((template) => matches(template, query)) : allTemplates;
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
      const templates = Object.values(await getJson(api));
      const collator = new Intl.Collator(langcode, { sensitivity: 'base', numeric: true });
      templates.sort((a, b) => collator.compare(a.label || a.id, b.label || b.id));
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
    if (!loading && !openMenu && !openDialogs.size && !els.toolbar.hidden && !document.hidden) {
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

  const copyIdFor = (label) => {
    const taken = new Set([...templateIds, ...Object.keys(usage ?? {})]);
    const stem = slug(label);
    let id = stem;
    for (let n = 2; taken.has(id); n++) {
      id = `${stem.slice(0, COPY_BASE_MAX)}_${n}`;
    }
    return id;
  };

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
      strings = table.strings ?? {};
      langcode = table.langcode || langcode;
      document.documentElement.lang = langcode;
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
})();
