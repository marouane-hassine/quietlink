// SPDX-License-Identifier: AGPL-3.0-or-later

/** "How it works": progressive security education without exposing any user content. */

import type { PublicConfig } from '../config';
import { t } from '../i18n';
import { el, showScreen } from '../ui/dom';

export function mountHow(main: HTMLElement, config: PublicConfig): () => void {
  const section = (id: string, title: string, content: Node): HTMLElement =>
    el('section', { class: 'how-section', 'aria-labelledby': id }, el('h2', { id }, title), content);

  const translatedList = (prefix: string, numbers: number[], className = '') =>
    el('ul', { class: className }, ...numbers.map((n) => el('li', {}, t(`${prefix}${n}`))));

  // Only the options this instance enables are described.
  const options = [1, ...(config.allowReadOnce ? [2] : []), ...(config.allowPassphrase ? [3] : []), 4, 5, 6];

  const render = () => {
    // A language change redraws the page: open questions and the scroll position are kept.
    const openFaq = [...main.querySelectorAll<HTMLDetailsElement>('.how-faq details')].map((d) => d.open);
    const scroll = window.scrollY;
    const flow = el(
      'ol',
      { class: 'how-flow' },
      ...[1, 2, 3].map((n) =>
        el('li', {}, el('h3', {}, t(`how.flow.step${n}.title`)), el('p', {}, t(`how.flow.step${n}.body`))),
      ),
    );

    const dataCards = el(
      'div',
      { class: 'how-data-grid' },
      el(
        'article',
        { class: 'how-data-card how-data-card-sees' },
        el('h3', {}, t('how.server.sees.title')),
        translatedList('how.server.sees.item', [1, 2, 3, 4, 5]),
      ),
      el(
        'article',
        { class: 'how-data-card how-data-card-never' },
        el('h3', {}, t('how.server.never.title')),
        translatedList('how.server.never.item', [1, 2, 3, 4]),
      ),
    );

    const linkExample = el(
      'div',
      { class: 'how-link-example', role: 'group', 'aria-label': t('how.link.exampleLabel') },
      // Fictional, untranslatable sample values (no real identifier or key).
      el('code', { class: 'how-link-path' }, 'https://example.invalid/p/7Hq2xLwP'),
      el('code', { class: 'how-link-fragment' }, '#Zk9vR3tY…'),
    );

    const faq = el(
      'div',
      { class: 'how-faq' },
      ...Array.from({ length: 6 }, (_, index) => {
        const number = index + 1;
        const item = el('details', {}, el('summary', {}, t(`how.faq.q${number}`)), el('p', {}, t(`how.faq.a${number}`)));
        item.open = openFaq[index] ?? false;
        return item;
      }),
    );

    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.how.title')),
      el('p', { class: 'lead how-lead' }, t('how.intro')),
      section('how-flow-title', t('how.flow.title'), flow),
      section('how-server-title', t('how.server.title'), dataCards),
      section('how-link-title', t('how.link.title'), el('div', { class: 'how-link-content' }, linkExample, el('p', { class: 'how-caption' }, t('how.link.exampleExplanation')))),
      section('how-options-title', t('how.options.title'), translatedList('how.options.item', options, 'how-options')),
      config.allowReadOnce
        ? section(
            'how-read-once-title',
            t('how.readOnce.title'),
            el(
              'div',
              {},
              el(
                'ol',
                { class: 'how-read-once' },
                ...(config.allowPassphrase ? [1, 2, 3, 4] : [1, 3, 4]).map((n) =>
                  el('li', {}, t(`how.readOnce.step${n}`, { action: t('read.reveal') })),
                ),
              ),
              config.allowPassphrase ? el('p', { class: 'how-caption how-read-once-limit' }, t('how.readOnce.limit')) : null,
            ),
          )
        : null,
      section('how-trust-title', t('how.trust.title'), el('p', { class: 'warning' }, t('how.limits'))),
      section('how-faq-title', t('how.faq.title'), faq),
      el('p', { class: 'how-actions' }, el('a', { href: '/', class: 'button button-primary' }, t('action.new'))),
    );
    if (scroll > 0) window.scrollTo(0, scroll);
  };

  render();
  return render;
}
