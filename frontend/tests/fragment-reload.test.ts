// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// A corrected key fragment restarts the page; in-page anchors (the skip link "#main") must
// never reload it: the reload would replace the key and lose decrypted content.
// Requirements: EXG-READ-038, EXG-A11Y-017.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { enhanceSkipLink, navigation, reloadOnFragmentChange } from '../src/ui/dom';

describe('reload on fragment change', () => {
  const original = navigation.reload;
  afterEach(() => {
    navigation.reload = original;
  });

  it('ignores in-page anchors such as the skip link and reloads for a key-shaped fragment', () => {
    const reload = vi.fn();
    navigation.reload = reload;
    document.body.innerHTML = '<a id="ql-skip" href="#main">Skip</a><main id="main"></main>';
    history.replaceState(null, '', '/p/AAAA#' + 'k'.repeat(43));
    reloadOnFragmentChange();

    history.replaceState(null, '', '/p/AAAA#main');
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(reload).not.toHaveBeenCalled();

    history.replaceState(null, '', '/p/AAAA#' + 'z'.repeat(43));
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(reload).toHaveBeenCalledOnce();
  });

  it('reloads for a corrected but malformed fragment so that "incomplete link" is shown again', () => {
    const reload = vi.fn();
    navigation.reload = reload;
    document.body.innerHTML = '<a id="ql-skip" href="#main">Skip</a><main id="main"></main><h2 id="details"></h2>';
    history.replaceState(null, '', '/p/AAAA#' + 'k'.repeat(43));
    reloadOnFragmentChange();

    history.replaceState(null, '', '/p/AAAA#details');
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    history.replaceState(null, '', '/p/AAAA');
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(reload).not.toHaveBeenCalled();

    history.replaceState(null, '', '/p/AAAA#k1');
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(reload).toHaveBeenCalledOnce();
  });

  it('moves focus to the content without touching the key fragment', () => {
    document.body.innerHTML = '<a id="ql-skip" href="#main">Skip</a><main id="main" tabindex="-1"></main>';
    const key = '#' + 'k'.repeat(43);
    history.replaceState(null, '', '/p/AAAA' + key);
    enhanceSkipLink();

    const skip = document.getElementById('ql-skip') as HTMLAnchorElement;
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    skip.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(location.hash).toBe(key);
    expect(document.activeElement?.id).toBe('main');
  });
});
