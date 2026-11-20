/**
 * The row menu, drawn in the editor page so it can hang out of the narrow frame.
 */
import { element, focusTrigger, hostDocument } from './dom.js';
import { ensureDialogStyles } from './host.js';
import { t } from './strings.js';

let openMenu = null;
const onResize = () => closeMenu();

export const closeMenu = ({ restoreFocus = false } = {}) => {
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

/** Whether any row menu is open. */
export const isMenuOpen = () => openMenu !== null;

/** Whether the menu of this "..." button is the open one. */
export const isMenuOpenFor = (button) => openMenu?.button === button;

/** Closes the menu on a click elsewhere, Escape or scrolling in the panel. */
export const installMenuDismissal = () => {
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
};

/** Opens a row's menu in the editor page, below its button or above if no room. */
export const showMenu = (button, row, name, entries) => {
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
