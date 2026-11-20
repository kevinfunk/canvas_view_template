/**
 * The editor page around the panel's iframe: navigation, its look, and the
 * stylesheet that dialogs and menus drawn there need.
 */
import { base } from './api.js';
import { hostDocument } from './dom.js';

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

export const openEditor = (id) => navigateParent(editorUrl(id));

export const closePanel = () => {
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

/**
 * Makes the host look like a Canvas side panel (no back bar, 296px wide).
 *
 * Does nothing if Canvas's markup is not as expected. `restoreHost` undoes it.
 */
let restoreHostLook = () => {};
export const adaptHost = () => {
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
    restoreHostLook = () => {
      bar.style.display = '';
      page.style.width = '';
      page.style.flex = '';
      page.style.borderRight = '';
    };
  } catch {
    // Not framed or parent unreachable.
  }
};

let dialogStyles = null;
let dialogStylesReady = Promise.resolve();
/** Loads the dialog stylesheet into the host page once; the menu must wait for it. */
export const ensureDialogStyles = () => {
  if (dialogStyles) {
    return dialogStylesReady;
  }
  dialogStyles = hostDocument.createElement('link');
  dialogStyles.rel = 'stylesheet';
  dialogStyles.href = new URL('dialog.css', import.meta.url).href;
  dialogStylesReady = new Promise((resolve) => {
    dialogStyles.onload = resolve;
    dialogStyles.onerror = resolve;
  });
  hostDocument.head.append(dialogStyles);
  return dialogStylesReady;
};

/** Undoes what the panel changed in the editor page. */
export const restoreHost = () => {
  dialogStyles?.remove();
  restoreHostLook();
};
