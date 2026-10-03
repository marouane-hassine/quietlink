// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Expiration countdown corrected for clock skew (§5.1, "Écran de lecture"):
 * remaining = expires_at − (server_time + (t1 − t0) / 2), then counted down with the
 * monotonic performance.now(); resynchronised on visibilitychange.
 */

export interface Sync {
  /** Seconds remaining at monotonic instant `at` (ms). */
  remaining: number;
  at: number;
  approximate: boolean;
}

export function synchronise(expiresAtIso: string, serverTimeIso: string, t0: number, t1: number): Sync {
  const expires = Date.parse(expiresAtIso);
  const server = Date.parse(serverTimeIso);
  const roundTrip = t1 - t0;
  return {
    remaining: (expires - (server + roundTrip / 2)) / 1000,
    at: t1,
    approximate: roundTrip > 5000,
  };
}

export function remainingAt(sync: Sync, now: number): number {
  return Math.max(0, sync.remaining - (now - sync.at) / 1000);
}

/** Thresholds announced to assistive technologies: 5 minutes, 1 minute, expiry. */
export function crossedThreshold(previous: number, current: number): 300 | 60 | 0 | null {
  for (const threshold of [300, 60, 0] as const) {
    if (previous > threshold && current <= threshold) return threshold;
  }
  return null;
}

/** Update every minute, every second during the last minute. */
export function nextTickMs(remaining: number): number {
  return remaining <= 60 ? 1000 : Math.max(1000, ((remaining % 60) || 60) * 1000);
}
