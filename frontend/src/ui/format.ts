// SPDX-License-Identifier: AGPL-3.0-or-later

import { locale } from '../i18n';

/** Size with decimal units localised by the browser (o/ko/Mo in French, B/kB/MB in English). */
export function formatBytes(bytes: number): string {
  const units = ['byte', 'kilobyte', 'megabyte'] as const;
  let value = bytes;
  let unit = 0;
  while (value >= 1000 && unit < units.length - 1) {
    value /= 1000;
    unit += 1;
  }
  return new Intl.NumberFormat(locale(), { style: 'unit', unit: units[unit], unitDisplay: 'short', maximumFractionDigits: unit === 0 ? 0 : 1 }).format(value);
}

/** Absolute date in the active language and the browser time zone, with the zone name. */
export function formatDate(epochMs: number): string {
  return new Intl.DateTimeFormat(locale(), { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }).format(new Date(epochMs));
}

/**
 * Relative time in the largest unit the value rounds to: the switch happens where rounding
 * would reach the next unit (59.5 s, 59.5 min, 23.5 h), never "in 24 hours" for a day.
 */
export function formatRelative(seconds: number): string {
  const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
  const abs = Math.abs(seconds);
  if (abs < 59.5) return rtf.format(Math.round(seconds), 'second');
  if (abs < 59.5 * 60) return rtf.format(Math.round(seconds / 60), 'minute');
  if (abs < 23.5 * 3600) return rtf.format(Math.round(seconds / 3600), 'hour');
  // "always": with "auto", one day would read "tomorrow" although it may end today.
  return new Intl.RelativeTimeFormat(locale(), { numeric: 'always' }).format(Math.round(seconds / 86400), 'day');
}
