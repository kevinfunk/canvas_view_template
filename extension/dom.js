/**
 * The panel's own elements and small DOM helpers.
 */
export const els = {
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

export const element = (tag, className, props = {}, doc = document) => {
  const node = doc.createElement(tag);
  if (className) {
    node.className = className;
  }
  return Object.assign(node, props);
};

/** The document that hosts dialogs: the editor page when framed. */
export const hostDocument = (() => {
  try {
    const doc = window.parent.document;
    return doc.body ? doc : document;
  } catch {
    return document;
  }
})();

export const showError = (message) => {
  els.error.textContent = message || '';
  els.errorBox.hidden = !message;
};

export const announce = (message) => {
  els.status.textContent = '';
  setTimeout(() => {
    els.status.textContent = message;
  }, 50);
};

/** Focuses a row's "..." button, kept shown until focus leaves it. */
export const focusTrigger = (button) => {
  if (!button?.isConnected) {
    els.newButton.focus();
    return;
  }
  const row = button.closest('.node');
  row?.classList.add('node--keep');
  button.addEventListener('blur', () => row?.classList.remove('node--keep'), { once: true });
  button.focus();
};
