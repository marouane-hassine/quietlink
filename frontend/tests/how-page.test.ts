// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (how-it-works education and accessibility): EXG-UX-074, EXG-SEC-001,
// EXG-SEC-002, EXG-SEC-003, EXG-I18N-001.

import { beforeEach, describe, expect, it as test } from 'vitest';
import ar from '../../translations/ar.json';
import en from '../../translations/en.json';
import es from '../../translations/es.json';
import fr from '../../translations/fr.json';
import it from '../../translations/it.json';
import { mountHow } from '../src/pages/how';
import { catalogKeys, setLocale } from '../src/i18n';

const catalogs = [en, fr, es, it, ar] as Record<string, unknown>[];
const howKeys = [
  'how.intro',
  'how.flow.title',
  'how.flow.step1.title',
  'how.flow.step1.body',
  'how.flow.step2.title',
  'how.flow.step2.body',
  'how.flow.step3.title',
  'how.flow.step3.body',
  'how.server.title',
  'how.server.sees.title',
  'how.server.sees.item1',
  'how.server.sees.item2',
  'how.server.sees.item3',
  'how.server.sees.item4',
  'how.server.never.title',
  'how.server.never.item1',
  'how.server.never.item2',
  'how.server.never.item3',
  'how.server.never.item4',
  'how.link.title',
  'how.link.exampleLabel',
  'how.link.exampleExplanation',
  'how.options.title',
  'how.options.item1',
  'how.options.item2',
  'how.options.item3',
  'how.options.item4',
  'how.options.item5',
  'how.options.item6',
  'how.readOnce.title',
  'how.readOnce.step1',
  'how.readOnce.step2',
  'how.readOnce.step3',
  'how.readOnce.step4',
  'how.trust.title',
  'how.limits',
  'how.faq.title',
  'how.faq.q1',
  'how.faq.a1',
  'how.faq.q2',
  'how.faq.a2',
  'how.faq.q3',
  'how.faq.a3',
  'how.faq.q4',
  'how.faq.a4',
  'how.faq.q5',
  'how.faq.a5',
  'how.faq.q6',
  'how.faq.a6',
];

describe('how-it-works page', () => {
  beforeEach(() => {
    setLocale('en');
    document.title = 'QuietLink';
    document.body.innerHTML = '<main id="main"></main>';
  });

  test('explains the data flow, server visibility and security options', () => {
    const main = document.getElementById('main') as HTMLElement;
    mountHow(main);

    expect(main.querySelector('h1')?.textContent).toBe('How it works');
    expect(main.querySelector('.how-flow')?.children).toHaveLength(3);
    expect(main.querySelectorAll('.how-data-card')).toHaveLength(2);
    expect(main.querySelector('.how-link-fragment')?.textContent).toBe('#key');
    expect(main.querySelectorAll('.how-options li')).toHaveLength(6);
    expect(main.querySelectorAll('.how-read-once li')).toHaveLength(4);
    expect(main.textContent).toContain('The server never receives');
  });

  test('keeps the FAQ progressively disclosed and accessible', () => {
    const main = document.getElementById('main') as HTMLElement;
    mountHow(main);

    const faq = main.querySelectorAll<HTMLDetailsElement>('.how-faq details');
    expect(faq).toHaveLength(6);
    expect([...faq].every((item) => !item.open)).toBe(true);
    expect(faq[0]?.querySelector('summary')?.textContent).toBe('Can the server read my text?');
  });

  test('provides every help translation key in every shipped catalogue', () => {
    for (const catalog of catalogs) {
      for (const key of howKeys) expect(catalog[key]).toBeTruthy();
    }
  });

  test('keeps the existing catalogue parity check aware of the new keys', () => {
    expect(catalogKeys('en')).toEqual(expect.arrayContaining(howKeys));
  });
});
