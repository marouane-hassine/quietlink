// SPDX-License-Identifier: AGPL-3.0-or-later

import { clearAlerts } from './announcer';

/** Small DOM builder: text is always set through textContent, never innerHTML. */

type Attrs = Record<string, string | boolean | undefined>;
type Child = Node | string | null | undefined | false;

export function el<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Attrs = {}, ...children: Child[]): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs)) {
    if (value === undefined || value === false) continue;
    if (name === 'class') node.className = String(value);
    else node.setAttribute(name, value === true ? '' : value);
  }
  for (const child of children) {
    if (child === null || child === undefined || child === false) continue;
    node.append(typeof child === 'string' ? document.createTextNode(child) : child);
  }
  return node;
}

export function byId<T extends HTMLElement>(id: string): T {
  const node = document.getElementById(id);
  if (!node) throw new Error(`Missing element #${id}`);
  return node as T;
}

let uid = 0;
export function nextId(prefix: string): string {
  uid += 1;
  return `${prefix}-${uid}`;
}

let redrawing = false;

/**
 * Runs a redraw of the current screen (language change) without moving focus: the change is
 * announced, focus stays where the user is (§6.6.1).
 */
export function redrawInPlace(redraw: () => void): void {
  const main = document.getElementById('main');
  const before = main ? snapshotFields(main) : null;
  redrawing = true;
  try {
    redraw();
  } finally {
    redrawing = false;
  }
  if (main && before) restoreFields(main, before);
}

type Field = HTMLInputElement | HTMLTextAreaElement;
interface FieldState {
  kind: string;
  value: string;
  checked: boolean;
  focused: boolean;
  selection: [number | null, number | null];
}

const fieldKind = (field: Field): string => (field instanceof HTMLInputElement ? `input:${field.type}` : 'textarea');
const fieldsOf = (root: HTMLElement): Field[] => [...root.querySelectorAll<Field>('input, textarea')];
const hasSelection = (field: Field): boolean => !(field instanceof HTMLInputElement) || ['text', 'password', 'search', 'url', 'tel'].includes(field.type);

function snapshotFields(root: HTMLElement): FieldState[] {
  return fieldsOf(root).map((field) => ({
    kind: fieldKind(field),
    value: field.value,
    checked: field instanceof HTMLInputElement && field.checked,
    focused: document.activeElement === field,
    selection: hasSelection(field) ? [field.selectionStart, field.selectionEnd] : [null, null],
  }));
}

/**
 * Screens are rebuilt with new element ids: typed values (a passphrase being entered), focus and
 * caret are carried over by position when the redrawn screen has the same fields, and only into
 * fields it left empty, so state a page restores itself always wins.
 */
function restoreFields(root: HTMLElement, before: FieldState[]): void {
  const after = fieldsOf(root);
  if (after.length !== before.length || after.some((field, i) => fieldKind(field) !== before[i]?.kind)) return;
  after.forEach((field, i) => {
    const state = before[i] as FieldState;
    if (field.value === '' && state.value !== '' && !(field instanceof HTMLInputElement && ['checkbox', 'radio'].includes(field.type))) field.value = state.value;
    if (state.focused && document.activeElement !== field) {
      field.focus({ preventScroll: true });
      const [start, end] = state.selection;
      if (start !== null && end !== null && hasSelection(field)) field.setSelectionRange(start, end);
    }
  });
}

/** Moves focus unless the current screen is only being redrawn. */
export function focusUnlessRedrawing(target: HTMLElement | null | undefined): void {
  if (!redrawing) target?.focus({ preventScroll: false });
}

let instanceName: string | null = null;

/** Instance name from the page configuration, used as the window title suffix. */
export function setInstanceName(name: string): void {
  instanceName = name;
}

/** Screen title followed by the instance name (read from the server title when not set). */
export function windowTitle(screen: string): string {
  if (instanceName !== null) return `${screen} · ${instanceName}`;
  const separator = document.title.lastIndexOf(' · ');
  return screen + (separator >= 0 ? document.title.slice(separator) : '');
}

/** Replaces the main content and moves focus to the new screen title (§6.6). */
export function showScreen(main: HTMLElement, ...nodes: (Node | null)[]): void {
  clearAlerts();
  main.replaceChildren(...nodes.filter((n): n is Node => n !== null));
  const heading = main.querySelector('h1, h2');
  // The window title names the current screen (WCAG 2.4.2); screen titles never hold secrets.
  const title = main.querySelector('h1')?.textContent?.trim();
  if (title) document.title = windowTitle(title);
  if (heading instanceof HTMLElement) {
    heading.tabIndex = -1;
    focusUnlessRedrawing(heading);
  }
}

/** Page navigation, behind an object so that tests can replace it (jsdom cannot reload). */
export const navigation = {
  reload: (): void => location.reload(),
};

let reloadInstalled = false;

/**
 * A link whose fragment (key or token) is corrected in the same tab only fires hashchange: the
 * page starts again from the new fragment instead of keeping the screen of the old one.
 */
export function reloadOnFragmentChange(): void {
  if (reloadInstalled) return;
  reloadInstalled = true;
  window.addEventListener('hashchange', () => {
    // Any other fragment (a corrected key, even a malformed one, which then shows "incomplete
    // link" again) restarts the page; an in-page anchor such as the skip link "#main" never
    // does, or the reload would replace the key and lose decrypted content.
    const fragment = location.hash.slice(1);
    let id = fragment;
    try {
      id = decodeURIComponent(fragment);
    } catch {
      // Not percent-encoded text: compared as written.
    }
    if (fragment !== '' && document.getElementById(fragment) === null && document.getElementById(id) === null) navigation.reload();
  });
}

/**
 * The skip link moves focus to the content without navigating: following "#main" would replace
 * the key fragment of the read and manage pages in the address bar.
 */
export function enhanceSkipLink(): void {
  document.getElementById('ql-skip')?.addEventListener('click', (event) => {
    const target = document.getElementById('main');
    if (target === null) return;
    event.preventDefault();
    target.focus();
  });
}

/**
 * Removes the key fragment from the address bar and the current history entry once it has no
 * further use (paste destroyed, deleted or unavailable): it no longer lingers in history,
 * bookmarks or screenshots (ADR-0009). Multi-read links keep it so they can be read again.
 */
export function forgetFragment(): void {
  if (location.hash !== '') history.replaceState(history.state, '', location.pathname + location.search);
}
