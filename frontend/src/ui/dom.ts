// SPDX-License-Identifier: AGPL-3.0-or-later

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

/** Replaces the main content and moves focus to the new screen title (§6.6). */
export function showScreen(main: HTMLElement, ...nodes: (Node | null)[]): void {
  main.replaceChildren(...nodes.filter((n): n is Node => n !== null));
  const heading = main.querySelector('h1, h2');
  if (heading instanceof HTMLElement) {
    heading.tabIndex = -1;
    heading.focus({ preventScroll: false });
  }
}
