// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Read-once reservation identifier (§6.3.1): written to sessionStorage before `open`, kept
 * for the reservation lifetime, erased after consumption or expiry. Never in localStorage,
 * a cookie, the URL or a log. Blocked storage only disables resumption.
 */

const PREFIX = 'ql-reservation-';

interface Stored {
  id: string;
  until: number;
}

export function saveReservation(pasteId: string, reservationId: string, now: number, seconds: number): void {
  try {
    sessionStorage.setItem(PREFIX + pasteId, JSON.stringify({ id: reservationId, until: now + seconds * 1000 } satisfies Stored));
  } catch {
    // Resumption after a reload is unavailable.
  }
}

export function loadReservation(pasteId: string, now: number): string | null {
  let raw: string | null = null;
  try {
    raw = sessionStorage.getItem(PREFIX + pasteId);
  } catch {
    return null;
  }
  if (raw === null) return null;
  try {
    const stored = JSON.parse(raw) as Partial<Stored>;
    if (typeof stored.id === 'string' && typeof stored.until === 'number' && stored.until > now) return stored.id;
  } catch {
    // Malformed entry: dropped below.
  }
  clearReservation(pasteId);
  return null;
}

/** Wall-clock end (ms) of the stored reservation, or null: shown on the Reveal screen after a reload. */
export function reservationUntil(pasteId: string, now: number): number | null {
  if (loadReservation(pasteId, now) === null) return null;
  try {
    const stored = JSON.parse(sessionStorage.getItem(PREFIX + pasteId) ?? '') as Stored;
    return stored.until;
  } catch {
    return null;
  }
}

export function clearReservation(pasteId: string): void {
  try {
    sessionStorage.removeItem(PREFIX + pasteId);
  } catch {
    // Nothing to clean.
  }
}

/** Erases every expired entry (any paste), so identifiers never outlive their reservation. */
export function clearExpiredReservations(now: number): void {
  try {
    for (const key of Object.keys(sessionStorage)) {
      if (key.startsWith(PREFIX)) loadReservation(key.slice(PREFIX.length), now);
    }
  } catch {
    // Storage blocked: nothing was stored either.
  }
}

