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
