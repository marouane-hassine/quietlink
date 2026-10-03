// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Keeps the bottom action bar above the virtual keyboard (§5.1): the visual viewport height
 * is exposed as --ql-keyboard-offset through the CSSOM (allowed by style-src 'self').
 */

export function followVirtualKeyboard(): void {
  const viewport = window.visualViewport;
  if (!viewport) return;
  const update = () => {
    const offset = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
    document.documentElement.style.setProperty('--ql-keyboard-offset', `${Math.round(offset)}px`);
  };
  viewport.addEventListener('resize', update);
  viewport.addEventListener('scroll', update);
  update();
}

/**
 * Publishes the height of the current screen's action bar as --ql-bar-height, used as the
 * scroll padding so a focused field is never hidden under the sticky bar (WCAG 2.4.11, §5.1).
 * The bar is replaced on each screen change and grows with zoom, wrapping and the gauge.
 */
export function followActionBar(main: HTMLElement): void {
  if (typeof ResizeObserver === 'undefined') return;
  let observed: Element | null = null;
  const measure = () => {
    const bar = main.querySelector('.action-bar');
    const height = bar instanceof HTMLElement ? Math.ceil(bar.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--ql-bar-height', `${height}px`);
  };
  const resize = new ResizeObserver(measure);
  const track = () => {
    const bar = main.querySelector('.action-bar');
    if (bar === observed) return;
    resize.disconnect();
    if (bar) resize.observe(bar);
    observed = bar;
    measure();
  };
  new MutationObserver(track).observe(main, { childList: true, subtree: true });
  track();
}
