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
  const api = `${base}/canvas/api/v0/config/view_template`;

  const TEMPLATE_ICON =
    '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linejoin="round"><rect x="2.5" y="1.5" width="10" height="4" rx="0.5"/><rect x="2.5" y="7.5" width="4" height="6" rx="0.5"/><rect x="8.5" y="7.5" width="4" height="2" rx="0.5"/><rect x="8.5" y="11.5" width="4" height="2" rx="0.5"/></svg>';
  const DOTS_ICON =
    '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.625 7.5C3.625 8.12132 3.12132 8.625 2.5 8.625C1.87868 8.625 1.375 8.12132 1.375 7.5C1.375 6.87868 1.87868 6.375 2.5 6.375C3.12132 6.375 3.625 6.87868 3.625 7.5ZM8.625 7.5C8.625 8.12132 8.12132 8.625 7.5 8.625C6.87868 8.625 6.375 8.12132 6.375 7.5C6.375 6.87868 6.87868 6.375 7.5 6.375C8.12132 6.375 8.625 6.87868 8.625 7.5ZM12.5 8.625C13.1213 8.625 13.625 8.12132 13.625 7.5C13.625 6.87868 13.1213 6.375 12.5 6.375C11.8787 6.375 11.375 6.87868 11.375 7.5C11.375 8.12132 11.8787 8.625 12.5 8.625Z" fill="currentColor"/></svg>';

  const els = {
    list: document.getElementById('list'),
    loading: document.getElementById('loading'),
    empty: document.getElementById('empty'),
    error: document.getElementById('error'),
    newButton: document.getElementById('new-button'),
    errorBox: document.getElementById('error-box'),
    retry: document.getElementById('retry-button'),
    status: document.getElementById('status'),
    close: document.getElementById('close-button'),
  };

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

  const csrfToken = async () => {
    const response = await fetch(`${base}/session/token`, { credentials: 'same-origin' });
    return response.text();
  };

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

  const editorUrl = (id) => `${base}/canvas/editor/view_template/${encodeURIComponent(id)}`;

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
   * Does nothing if Canvas's markup is not as expected.
   */
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
      window.addEventListener('pagehide', () => {
        bar.style.display = '';
        page.style.width = '';
        page.style.flex = '';
        page.style.borderRight = '';
      });
    } catch {
      // Not framed or parent unreachable.
    }
  };
  adaptHost();

  const element = (tag, className, props = {}) => {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    return Object.assign(node, props);
  };

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
    window.removeEventListener('resize', closeMenu);
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
  window.addEventListener('pagehide', () => closeMenu());
  document.addEventListener('scroll', () => closeMenu(), true);

  /** Opens a row's menu in the editor page, below its button or above if no room. */
  const showMenu = (button, row, name, entries) => {
    closeMenu();
    const styles = ensureDialogStyles();
    const doc = hostDocument;
    const make = (tag, className, props = {}) => Object.assign(doc.createElement(tag), { className, ...props });
    const menu = make('div', 'cvt-menu');
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', `Actions for ${name}`);
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
    window.addEventListener('resize', closeMenu);

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

  // View display labels per template id; `usageKnown` is false if the request failed.
  let usage = {};
  let usageKnown = false;
  let templateIds = new Set();
  const loadUsage = async () => {
    try {
      const response = await check(
        await fetch(`${base}/canvas-view-template/usage`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }),
      );
      usage = (await response.json()).templates ?? {};
      usageKnown = true;
    } catch (error) {
      usage = {};
      usageKnown = false;
    }
  };

  const usageText = (id) => {
    if (!usageKnown) {
      return 'Usage unknown';
    }
    const labels = (usage[id] ?? []).map((display) => display.label);
    return labels.length ? `Used by ${summarize(labels)}` : 'Not used';
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
    dots.setAttribute('aria-label', `Actions for ${name}`);
    dots.setAttribute('aria-haspopup', 'menu');
    dots.setAttribute('aria-expanded', 'false');
    dots.innerHTML = DOTS_ICON;

    const entries = [
      { label: 'Edit', run: () => openEditor(template.id) },
      { label: 'Duplicate', run: () => openDuplicateDialog(template, dots) },
      { label: 'Delete', danger: true, run: () => openDeleteDialog(template, dots) },
    ];
    const toggleMenu = () => {
      if (openMenu?.button === dots) {
        closeMenu({ restoreFocus: true });
      } else {
        showMenu(dots, item, name, entries);
      }
    };
    dots.addEventListener('click', (event) => {
      event.stopPropagation();
      toggleMenu();
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

  const render = (templates) => {
    els.list.replaceChildren(...templates.map(renderNode));
    els.loading.hidden = true;
    els.empty.hidden = templates.length > 0;
  };

  const load = async ({ announceCount = true } = {}) => {
    showError('');
    try {
      const response = await check(
        await fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
      );
      const templates = Object.values(await response.json());
      templateIds = new Set(templates.map((template) => template.id));
      await loadUsage();
      render(templates);
      els.newButton.hidden = false;
      if (announceCount) {
        announce(templates.length === 1 ? '1 view template.' : `${templates.length} view templates.`);
      }
    } catch (error) {
      els.loading.hidden = true;
      els.newButton.hidden = true;
      showError(`Could not load view templates: ${error.message}`);
    }
  };
  els.retry.addEventListener('click', () => {
    els.loading.hidden = false;
    load();
  });

  /** The document that hosts dialogs: the editor page when framed. */
  const hostDocument = (() => {
    try {
      const doc = window.parent.document;
      return doc.body ? doc : document;
    } catch {
      return document;
    }
  })();

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
  window.addEventListener('pagehide', () => {
    for (const close of [...openDialogs]) {
      close();
    }
    dialogStyles?.remove();
  });

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
    const make = (tag, className, props = {}) => {
      const node = doc.createElement(tag);
      node.className = className;
      return Object.assign(node, props);
    };

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
        caption.append(' ', make('em', '', { textContent: '(optional)' }));
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
    const cancel = make('button', `cvt-button cvt-button--${options.cancelClass}`, { type: 'button', textContent: 'Cancel' });
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
      const values = Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value]));
      try {
        await options.onConfirm(values, dialog);
        busy = false;
        close();
      } catch (failure) {
        busy = false;
        confirm.disabled = false;
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

  const request = async (url, method, body) => {
    const headers = { 'X-CSRF-Token': await csrfToken(), Accept: 'application/json' };
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

  const usedBy = async (template) => {
    try {
      const response = await check(
        await fetch(`${base}/canvas-view-template/usage/${encodeURIComponent(template.id)}`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }),
      );
      return (await response.json()).displays.map((display) => display.label);
    } catch (error) {
      return [];
    }
  };

  const summarize = (labels) => {
    const shown = labels.slice(0, 3);
    const more = labels.length - shown.length;
    if (more > 0) {
      return `${shown.join(', ')} and ${more} more`;
    }
    return shown.length > 1 ? `${shown.slice(0, -1).join(', ')} and ${shown.at(-1)}` : shown[0];
  };

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
        title: 'Template is in use',
        message: `"${name}" is used by ${summarize(views)}. Choose another template in the Format settings of ${views.length === 1 ? 'that view' : 'those views'} first, then delete it.`,
        confirmLabel: 'OK',
        confirmClass: 'solid',
        cancelClass: 'outline',
        hideCancel: true,
        returnFocus: () => focusTrigger(trigger),
        onConfirm: async () => {},
      });
      return;
    }
    showDialog({
      title: 'Delete template',
      message: `Are you sure you want to delete "${name}"? This action cannot be undone.`,
      confirmLabel: 'Delete',
      confirmClass: 'danger',
      cancelClass: 'outline',
      focus: 'cancel',
      returnFocus: () => focusTrigger(trigger),
      onConfirm: async () => {
        try {
          await request(`${api}/${encodeURIComponent(template.id)}`, 'DELETE');
        } catch (failure) {
          throw new Error(`Could not delete "${name}": ${failure.message}`);
        }
        await load({ announceCount: false });
        announce(`Deleted "${name}".`);
      },
    });
  };

  const slug = (label) =>
    label
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      // Machine names must start with a letter.
      .replace(/^(\d)/, 't_$1')
      .slice(0, 64) || 'template';

  // Same rule as the server's.
  const MACHINE_NAME = /^[a-z][a-z0-9_]{0,63}$/;

  const copyIdFor = (label) => {
    const taken = new Set([...templateIds, ...Object.keys(usage)]);
    const base = slug(label);
    let id = base;
    for (let n = 2; taken.has(id); n++) {
      id = `${base.slice(0, 60)}_${n}`;
    }
    return id;
  };

  const openDuplicateDialog = (template, trigger) => {
    closeMenu();
    const name = template.label || template.id;
    const label = `Copy of ${name}`;
    let idEdited = false;
    showDialog({
      title: 'Duplicate view template',
      message: 'Starts from what the editor shows now, including changes not yet published. Views keep using the original until you choose the copy in their Format settings.',
      fields: [
        { key: 'label', label: 'Label', value: label },
        { key: 'id', label: 'Machine name', value: copyIdFor(label) },
      ],
      confirmLabel: 'Duplicate and edit',
      confirmClass: 'solid',
      cancelClass: 'gray',
      focus: 'label',
      returnFocus: () => focusTrigger(trigger),
      onInput: (key, value, inputs) => {
        if (key === 'id') {
          idEdited = true;
        } else if (key === 'label' && !idEdited) {
          inputs.id.value = copyIdFor(value);
        }
      },
      onConfirm: async (values) => {
        const id = values.id.trim();
        const copyLabel = values.label.trim();
        const invalid = (message, field) => Object.assign(new Error(message), { field });
        if (copyLabel === '') {
          throw invalid('Enter a label.', 'label');
        }
        if (!MACHINE_NAME.test(id)) {
          throw invalid('The machine name must start with a letter and use only lowercase letters, numbers and underscores.', 'id');
        }
        try {
          await request(`${base}/canvas-view-template/duplicate/${encodeURIComponent(template.id)}`, 'POST', { id, label: copyLabel });
        } catch (failure) {
          throw failure.status === 409
            ? invalid(`A view template with the machine name "${id}" already exists.`, 'id')
            : new Error(`Could not duplicate the view template: ${failure.message}`);
        }
        openEditor(id);
      },
    });
  };

  const openCreateDialog = () => {
    closeMenu();
    let idEdited = false;
    showDialog({
      title: 'New view template',
      fields: [
        { key: 'label', label: 'Label' },
        { key: 'id', label: 'Machine name' },
        { key: 'description', label: 'Description', optional: true },
      ],
      confirmLabel: 'Create and edit',
      confirmClass: 'solid',
      cancelClass: 'gray',
      focus: 'label',
      onInput: (key, value, inputs) => {
        if (key === 'id') {
          idEdited = true;
        } else if (key === 'label' && !idEdited) {
          inputs.id.value = value.trim() ? slug(value) : '';
        }
      },
      onConfirm: async (values) => {
        const id = values.id.trim();
        const label = values.label.trim();
        const invalid = (message, field) => Object.assign(new Error(message), { field });
        if (label === '') {
          throw invalid('Enter a label.', 'label');
        }
        if (!MACHINE_NAME.test(id)) {
          throw invalid('The machine name must start with a letter and use only lowercase letters, numbers and underscores.', 'id');
        }
        const body = { id, label };
        if (values.description.trim() !== '') {
          body.description = values.description.trim();
        }
        try {
          await request(api, 'POST', body);
        } catch (failure) {
          throw failure.status === 409
            ? invalid(`A view template with the machine name "${id}" already exists.`, 'id')
            : new Error(`Could not create the view template: ${failure.message}`);
        }
        openEditor(id);
      },
    });
  };
  els.newButton.addEventListener('click', openCreateDialog);

  load();
})();
