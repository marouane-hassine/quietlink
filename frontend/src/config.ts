// SPDX-License-Identifier: AGPL-3.0-or-later

/** Public instance settings provided by the server page in <meta name="ql-config">. */

import type { Expiration } from './crypto/constants';

export interface PublicConfig {
  page: 'create' | 'read' | 'manage' | 'how';
  enabledLocales: string[];
  /** Name and writing direction of each enabled locale (catalogues loaded on demand). */
  locales?: { code: string; name: string; dir: 'ltr' | 'rtl' }[];
  defaultExpiration: Expiration;
  expirations: Expiration[];
  allowReadOnce: boolean;
  allowPassphrase: boolean;
  maxEnvelopeBytes: number;
  kdf: { m: number; t: number };
  enableQrCode: boolean;
  allowPrint?: boolean;
  allowExport?: boolean;
  darkMode: 'auto' | 'light' | 'dark';
  templates: string[];
  challenges?: { open: string; status: string };
}

export function readConfig(): PublicConfig {
  const meta = document.querySelector('meta[name="ql-config"]');
  const raw = meta?.getAttribute('content');
  if (!raw) throw new Error('Missing page configuration');
  return JSON.parse(raw) as PublicConfig;
}
