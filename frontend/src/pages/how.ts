// SPDX-License-Identifier: AGPL-3.0-or-later

/** "How it works": progressive security education without exposing any user content. */

import { t } from '../i18n';
import { el, showScreen } from '../ui/dom';

export function mountHow(main: HTMLElement): () => void {
  const section = (id: string, title: string, content: Node): HTMLElement =>
    el('section', { class: 'how-section', 'aria-labelledby': id }, el('h2', { id }, title), content);

  const translatedList = (prefix: string, count: number, className = '') =>
    el(
      'ul',
      { class: className },
      ...Array.from({ length: count }, (_, index) => el('li', {}, t(`${prefix}${index + 1}`))),
    );

  const render = () => {
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
        translatedList('how.server.sees.item', 4),
      ),
      el(
        'article',
        { class: 'how-data-card how-data-card-never' },
        el('h3', {}, t('how.server.never.title')),
        translatedList('how.server.never.item', 4),
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
        return el('details', {}, el('summary', {}, t(`how.faq.q${number}`)), el('p', {}, t(`how.faq.a${number}`)));
      }),
    );

    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.how.title')),
      el('p', { class: 'lead how-lead' }, t('how.intro')),
      section('how-flow-title', t('how.flow.title'), flow),
      section('how-server-title', t('how.server.title'), dataCards),
      section('how-link-title', t('how.link.title'), el('div', { class: 'how-link-content' }, linkExample, el('p', { class: 'how-caption' }, t('how.link.exampleExplanation')))),
      section('how-options-title', t('how.options.title'), translatedList('how.options.item', 6, 'how-options')),
      section(
        'how-read-once-title',
        t('how.readOnce.title'),
        el(
          'div',
          {},
          el(
            'ol',
            { class: 'how-read-once' },
            ...[1, 2, 3, 4].map((n) => el('li', {}, t(`how.readOnce.step${n}`, { action: t('read.reveal') }))),
          ),
          el('p', { class: 'how-caption how-read-once-limit' }, t('how.readOnce.limit')),
        ),
      ),
      section('how-trust-title', t('how.trust.title'), el('p', { class: 'warning' }, t('how.limits'))),
      section('how-faq-title', t('how.faq.title'), faq),
      el('p', { class: 'how-actions' }, el('a', { href: '/', class: 'button button-primary' }, t('action.new'))),
    );
  };

  render();
  return render;
}
