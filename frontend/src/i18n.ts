// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Translation catalogs (translations/*.json, shared with the server templates).
 * English is the mandatory fallback; only the language preference is remembered.
 */

import en from '../../translations/en.json';
import fr from '../../translations/fr.json';

type Catalog = Record<string, unknown> & { _meta: { locale: string; name: string; dir: 'ltr' | 'rtl' } };
/** Built in: English (mandatory fallback) and French (required in V1, §6.6.1). */
const CATALOGS: Record<string, Catalog> = { en: en as Catalog, fr: fr as Catalog };
/** Every other catalogue in translations/ is a separate chunk loaded on demand: adding a
 * language is adding a file, no code change (§6.6.1). */
const LOADERS = import.meta.glob<Catalog>('../../translations/*.json', { import: 'default' });

const loaderFor = (code: string) => (/^[a-z]{2}$/.test(code) ? LOADERS[`../../translations/${code}.json`] : undefined);

/** Loads a catalogue before setLocale(); false when no such catalogue exists. */
export async function loadLocale(code: string): Promise<boolean> {
  if (code in CATALOGS) return true;
  const loader = loaderFor(code);
  if (!loader) return false;
  CATALOGS[code] = await loader();
  return true;
}
const STORAGE_KEY = 'ql-locale';

let active: Catalog = CATALOGS.en as Catalog;

/** Languages offered in the selector; names come from the page configuration when given, so
 * that catalogues are only loaded when chosen. */
export function availableLocales(enabled: string[], known: readonly { code: string; name: string; dir?: string }[] = []): { code: string; name: string }[] {
  return enabled.flatMap((code) => {
    const name = known.find((entry) => entry.code === code)?.name ?? CATALOGS[code]?._meta.name;
    return name !== undefined && (code in CATALOGS || loaderFor(code)) ? [{ code, name }] : [];
  });
}

function stored(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

/** Explicit choice, then browser preference, then English. */
export function selectLocale(enabled: string[]): string {
  const candidates = [stored(), ...navigator.languages.map((l) => l.toLowerCase().split('-')[0])];
  for (const candidate of candidates) {
    if (candidate && enabled.includes(candidate) && (candidate in CATALOGS || loaderFor(candidate))) return candidate;
  }
  return 'en';
}

export function setLocale(code: string, remember = false): void {
  active = CATALOGS[code] ?? (CATALOGS.en as Catalog);
  document.documentElement.lang = active._meta.locale;
  document.documentElement.dir = active._meta.dir;
  if (remember) {
    try {
      localStorage.setItem(STORAGE_KEY, active._meta.locale);
    } catch {
      // Private mode: the preference is simply not remembered.
    }
  }
}

export function locale(): string {
  return active._meta.locale;
}

/** Translates a key; {name} placeholders are replaced by values (never by HTML). */
export function t(key: string, values: Record<string, string | number> = {}): string {
  const raw = active[key] ?? (CATALOGS.en as Catalog)[key];
  const text = typeof raw === 'string' ? raw : key;
  return text.replace(/\{(\w+)\}/g, (match, name: string) => (name in values ? String(values[name]) : match));
}

export function catalogKeys(code: string): string[] {
  return Object.keys(CATALOGS[code] ?? {}).filter((k) => k !== '_meta');
}
