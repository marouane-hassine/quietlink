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
  redrawing = true;
  try {
    redraw();
  } finally {
    redrawing = false;
  }
}

/** Moves focus unless the current screen is only being redrawn. */
export function focusUnlessRedrawing(target: HTMLElement | null | undefined): void {
  if (!redrawing) target?.focus({ preventScroll: false });
}

/** Replaces the main content and moves focus to the new screen title (§6.6). */
export function showScreen(main: HTMLElement, ...nodes: (Node | null)[]): void {
  clearAlerts();
  main.replaceChildren(...nodes.filter((n): n is Node => n !== null));
  const heading = main.querySelector('h1, h2');
  if (heading instanceof HTMLElement) {
    heading.tabIndex = -1;
    focusUnlessRedrawing(heading);
  }
}

/**
 * Removes the key fragment from the address bar and the current history entry once it has no
 * further use (paste destroyed, deleted or unavailable): it no longer lingers in history,
 * bookmarks or screenshots (ADR-0009). Multi-read links keep it so they can be read again.
 */
export function forgetFragment(): void {
  if (location.hash !== '') history.replaceState(history.state, '', location.pathname + location.search);
}
