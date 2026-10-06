// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (i18n §6.6.1): EXG-I18N-003, EXG-I18N-005, EXG-I18N-007, EXG-I18N-008, EXG-I18N-009.

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { availableLocales, loadLocale, locale, selectLocale, setLocale, t } from '../src/i18n';
import { fieldIdForLabel, isSensitiveLabel } from '../src/templates';

const directory = join(process.cwd(), 'translations');
const catalogs = Object.fromEntries(readdirSync(directory).filter((f) => f.endsWith('.json')).map((f) => [f.slice(0, -5), JSON.parse(readFileSync(join(directory, f), 'utf8')) as Record<string, unknown>]));
const placeholders = (value: unknown) => [...String(value).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

afterEach(() => {
  setLocale('en');
  vi.restoreAllMocks();
});

describe('shipped catalogues', () => {
  it('ships Arabic, Spanish and Italian next to English and French', () => {
    expect(Object.keys(catalogs).sort()).toEqual(['ar', 'en', 'es', 'fr', 'it']);
  });

  it.each(Object.keys(catalogs))('%s has every key and placeholder of English, and declares its direction', (code) => {
    const catalog = catalogs[code] as Record<string, unknown>;
    const english = catalogs.en as Record<string, unknown>;
    expect(Object.keys(catalog)).toEqual(Object.keys(english));
    for (const key of Object.keys(english)) if (key !== '_meta') expect(placeholders(catalog[key]), `${code} ${key}`).toEqual(placeholders(english[key]));
    expect((catalog._meta as { dir: string }).dir).toBe(code === 'ar' ? 'rtl' : 'ltr');
    // No bidi control characters: direction comes from dir attributes.
    expect(JSON.stringify(catalog)).not.toMatch(/[‎‏‪-‮⁦-⁩]/);
  });
});

describe('template field labels', () => {
  it.each(Object.keys(catalogs))('%s gives every template field a distinct label (labels identify fields)', (code) => {
    const catalog = catalogs[code] as Record<string, string>;
    const labels = Object.entries(catalog).filter(([key]) => key.startsWith('tpl.field.') && key !== 'tpl.field.copy' && key !== 'tpl.field.copied').map(([, value]) => value.toLowerCase());
    expect(labels.filter((label, index) => labels.indexOf(label) !== index)).toEqual([]);
  });
});

describe('locale loading', () => {
  it('loads a catalogue on demand and applies its direction to the document', async () => {
    expect(await loadLocale('ar')).toBe(true);
    setLocale('ar');
    expect(locale()).toBe('ar');
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
    expect(t('action.copy')).toBe((catalogs.ar as Record<string, string>)['action.copy']);
    expect(await loadLocale('xx')).toBe(false);
  });

  it('follows the browser preference among enabled languages', () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['it-IT', 'en']);
    localStorage.clear();
    expect(selectLocale(['en', 'it', 'es'])).toBe('it');
    expect(selectLocale(['en', 'es'])).toBe('en');
  });

  it('names languages from the page configuration, without loading their catalogues', () => {
    expect(availableLocales(['en', 'ar'], [{ code: 'en', name: 'English', dir: 'ltr' }, { code: 'ar', name: 'العربية', dir: 'rtl' }])).toEqual([
      { code: 'en', name: 'English' },
      { code: 'ar', name: 'العربية' },
    ]);
  });
});

describe('templates in every language', () => {
  it('recognises sensitive field labels written in any shipped language', () => {
    for (const code of Object.keys(catalogs)) {
      const label = (catalogs[code] as Record<string, string>)['tpl.field.password'] as string;
      expect(isSensitiveLabel(label), code).toBe(true);
      expect(fieldIdForLabel(label), code).toBe('password');
    }
  });
});

describe('catalogue loading failures', () => {
  it('reports a catalogue that cannot be fetched instead of throwing (offline, stale deploy)', async () => {
    // Spanish is not loaded by the other tests of this file (catalogues are cached once loaded).
    const failing = { '../../translations/es.json': () => Promise.reject(new TypeError('Failed to fetch')) };
    await expect(loadLocale('es', failing)).resolves.toBe(false);
  });

  it('never switches to a language whose catalogue is not loaded', () => {
    setLocale('en');
    expect(setLocale('xx')).toBe(false);
    expect(locale()).toBe('en');
  });
});

describe('terminology of the how-it-works page', () => {
  // The explanation page uses the words of the interface it explains (EXG-I18N-009, EXG-UX-002):
  // the passphrase term of the creation option and "management link" (manage.title).
  const terms: Record<string, { passphrase: string; others: string[]; deletionLink: string }> = {
    en: { passphrase: 'passphrase', others: ['secret phrase', 'password'], deletionLink: 'deletion link' },
    fr: { passphrase: 'phrase secrète', others: ['mot de passe'], deletionLink: 'lien de suppression' },
    es: { passphrase: 'frase secreta', others: ['frase de contraseña', 'contraseña'], deletionLink: 'enlace de eliminación' },
    it: { passphrase: 'passphrase', others: ['frase segreta', 'password'], deletionLink: 'link di eliminazione' },
    ar: { passphrase: 'عبارة', others: ['كلمة المرور', 'كلمة السر'], deletionLink: 'رابط الحذف' },
  };

  it.each(Object.keys(terms))('%s uses the passphrase term of the creation option', (code) => {
    const catalog = catalogs[code] as Record<string, string>;
    const term = terms[code] as (typeof terms)[string];
    expect((catalog['options.passphrase'] ?? '').toLowerCase()).toContain(term.passphrase);
    for (const [key, value] of Object.entries(catalog)) {
      if (!key.startsWith('how.')) continue;
      for (const other of term.others) expect(value.toLowerCase(), `${code} ${key}`).not.toContain(other);
    }
  });

  it.each(Object.keys(terms))('%s calls the deletion link the management link, as the result screen does', (code) => {
    const catalog = catalogs[code] as Record<string, string>;
    const term = terms[code] as (typeof terms)[string];
    const management = (catalog['manage.title'] ?? '').toLowerCase();
    for (const [key, value] of Object.entries(catalog)) {
      if (key.startsWith('how.')) expect(value.toLowerCase(), `${code} ${key}`).not.toContain(term.deletionLink);
    }
    for (const key of ['how.options.item4', 'how.faq.q4', 'how.faq.a4']) expect((catalog[key] ?? '').toLowerCase(), `${code} ${key}`).toContain(management);
  });

  it('says code, not software, in Arabic, like the format selector', () => {
    const ar = catalogs.ar as Record<string, string>;
    expect(ar['how.options.item5']).not.toContain('البرمجيات');
    expect(ar['how.options.item5']).toContain('الشيفرة البرمجية');
  });

  it('calls the share link "link di condivisione" in Italian, like result.shareLink', () => {
    const it = catalogs.it as Record<string, string>;
    expect(it['result.shareLink']?.toLowerCase()).toContain('link di condivisione');
    for (const [key, value] of Object.entries(it)) if (typeof value === 'string') expect(value, key).not.toMatch(/link condiviso/i);
    expect(it['how.link.title']).toBe('Capire il link di condivisione');
  });

  it('names read-once in Arabic with the words of the Secret preset', () => {
    const ar = catalogs.ar as Record<string, string>;
    expect(ar['preset.secret']).toContain('قراءة لمرة واحدة');
    for (const [key, value] of Object.entries(ar)) if (typeof value === 'string') expect(value, key).not.toMatch(/قراءة واحدة|القراءة الواحدة/);
  });

  it.each(Object.keys(catalogs))('%s reminds to clear the clipboard in every copy toast of a secret', (code) => {
    const catalog = catalogs[code] as Record<string, string>;
    const reminder = (catalog['read.copied'] ?? '').split(/(?<=\.) /).at(-1) ?? '';
    expect(reminder.length).toBeGreaterThan(10);
    for (const key of ['passphrase.copied', 'tpl.field.copied']) expect(catalog[key], `${code} ${key}`).toContain(reminder);
  });

  it('applies the reviewed wording of the French, Spanish and Italian catalogues', () => {
    const [fr, es, it] = [catalogs.fr, catalogs.es, catalogs.it] as Record<string, string>[];
    expect(fr?.['tpl.field.copied']).toBe('{label} : copié. Pensez à vider votre presse-papiers après usage.');
    expect(fr?.['tpl.field.database']).toBe('Base de données');
    expect(fr?.['read.revealTitle']).toBe('Un texte confidentiel a été partagé avec vous');
    expect(fr?.['read.wrap']).toBe('Retour à la ligne automatique');
    expect(es?.['preset.suggestSecret']).toContain('preajuste');
    expect(es?.['how.link.title']).toBe('Entender el enlace para compartir');
    expect(es?.['tpl.field.fingerprint']).toBe('Huella');
    expect(it?.['how.intro']).toContain(' – o non può – ');
  });
});
