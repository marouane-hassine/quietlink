// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Translation catalogs (translations/*.json, shared with the server templates).
 * English is the mandatory fallback; only the language preference is remembered.
 */

import en from '../../translations/en.json';
import fr from '../../translations/fr.json';

type Catalog = Record<string, unknown> & { _meta: { locale: string; name: string; dir: 'ltr' | 'rtl' } };
const CATALOGS: Record<string, Catalog> = { en: en as Catalog, fr: fr as Catalog };
const STORAGE_KEY = 'ql-locale';

let active: Catalog = CATALOGS.en as Catalog;

export function availableLocales(enabled: string[]): { code: string; name: string }[] {
  return enabled.filter((code) => code in CATALOGS).map((code) => ({ code, name: (CATALOGS[code] as Catalog)._meta.name }));
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
    if (candidate && enabled.includes(candidate) && candidate in CATALOGS) return candidate;
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
