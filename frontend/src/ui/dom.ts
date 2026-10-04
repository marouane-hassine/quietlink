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

/**
 * Removes the key fragment from the address bar and the current history entry once it has no
 * further use (paste destroyed, deleted or unavailable): it no longer lingers in history,
 * bookmarks or screenshots (ADR-0009). Multi-read links keep it so they can be read again.
 */
export function forgetFragment(): void {
  if (location.hash !== '') history.replaceState(history.state, '', location.pathname + location.search);
}
