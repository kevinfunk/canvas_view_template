/**
 * Modal dialogs, drawn in the editor page so they cover the whole editor.
 */
import { element, hostDocument } from './dom.js';
import { ensureDialogStyles } from './host.js';
import { t } from './strings.js';

/** Close functions of the open dialogs, so they can be closed when the panel goes. */
export const openDialogs = new Set();

/**
 * Shows a modal dialog in the host page.
 *
 * `onConfirm` rejecting keeps the dialog open and shows the error.
 * `focus` is 'cancel' or a field key; `returnFocus` moves focus on close.
 */
export const showDialog = (options) => {
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
