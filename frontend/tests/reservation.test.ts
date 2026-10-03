// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-READ-035, EXG-SEC-026, EXG-CRYPTO-074.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { clearReservation, loadReservation, saveReservation } from '../src/reservation';
import { retrying } from '../src/api';

afterEach(() => {
  vi.restoreAllMocks();
  sessionStorage.clear();
  localStorage.clear();
});

describe('reservation identifier', () => {
  it('is kept in sessionStorage only, until it expires', () => {
    saveReservation('paste', 'rid-value', 1000, 60);
    expect(loadReservation('paste', 30_000)).toBe('rid-value');
    expect(localStorage.length).toBe(0);
    expect(loadReservation('paste', 61_001)).toBeNull();
    expect(sessionStorage.length).toBe(0);
  });

  it('can be cleared after consumption', () => {
    saveReservation('paste', 'rid-value', 0, 60);
    clearReservation('paste');
    expect(loadReservation('paste', 1)).toBeNull();
  });

  it('degrades to no resumption when storage throws', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    expect(() => saveReservation('paste', 'x', 0, 60)).not.toThrow();
    expect(loadReservation('paste', 1)).toBeNull();
    expect(() => clearReservation('paste')).not.toThrow();
  });
});

describe('idempotent resend', () => {
  it('retries the exact same call after network errors only', async () => {
    const { ApiError } = await import('../src/api');
    const call = vi.fn().mockRejectedValueOnce(new ApiError('network')).mockResolvedValueOnce('ok');
    await expect(retrying(call, 3, 0)).resolves.toBe('ok');
    expect(call).toHaveBeenCalledTimes(2);

    const refused = vi.fn().mockRejectedValue(new ApiError('unavailable'));
    await expect(retrying(refused, 3, 0)).rejects.toThrow();
    expect(refused).toHaveBeenCalledTimes(1);
  });
});
