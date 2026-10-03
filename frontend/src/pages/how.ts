// SPDX-License-Identifier: AGPL-3.0-or-later

/** "How it works" in three steps, with the trust limit (§5.1, §7.5). */

import { t } from '../i18n';
import { el, showScreen } from '../ui/dom';

export function mountHow(main: HTMLElement): () => void {
  const render = () =>
    showScreen(
      main,
      el('h1', { class: 'page-title' }, t('page.how.title')),
      el(
        'ol',
        { class: 'steps' },
        ...[1, 2, 3].map((n) => el('li', {}, el('h2', {}, t(`how.step${n}.title`)), el('p', {}, t(`how.step${n}.body`)))),
      ),
      el('p', { class: 'notice' }, t('how.limits')),
      el('a', { href: '/', class: 'button button-primary' }, t('action.new')),
    );
  render();
  return render;
}
