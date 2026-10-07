// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements (recoverable network states, §5.1, §12 journey C, §16.1): EXG-UX-120, EXG-TEST-077.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError } from '../src/api';

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('API client', () => {
  it('gives up on a request that never answers, as a recoverable network error', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    vi.stubGlobal('fetch', (_url: string, init: RequestInit) => new Promise((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => reject(new DOMException('timeout', 'TimeoutError')));
    }));
    const pending = api.status('dummy-id', {}).catch((error: unknown) => error);
    await vi.advanceTimersByTimeAsync(30_000);

    const error = await pending;
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).kind).toBe('network');
  });

  it('treats 422 (key reused with another body) as final: resending cannot succeed', async () => {
    vi.stubGlobal('fetch', async () => ({ status: 422, ok: false, headers: new Headers(), json: async () => ({}) }));
    const error = await api.create('{}', 'dummy-key').catch((e: unknown) => e);

    expect((error as ApiError).kind).toBe('refused');
  });

  it('gives a large creation time to upload on a slow connection before giving up', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    let aborted = false;
    vi.stubGlobal('fetch', (_url: string, init: RequestInit) => new Promise((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => {
        aborted = true;
        reject(new DOMException('timeout', 'TimeoutError'));
      });
    }));
    const body = 'x'.repeat(1_400_000);
    const pending = api.create(body, 'dummy-key').catch((error: unknown) => error);
    await vi.advanceTimersByTimeAsync(31_000);
    expect(aborted).toBe(false);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(aborted).toBe(true);
    expect(((await pending) as ApiError).kind).toBe('network');
  });

  it('also gives up when the answer stalls while its body is being read', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    vi.stubGlobal('fetch', async (_url: string, init: RequestInit) => ({
      status: 200, ok: true, headers: new Headers(),
      json: () => new Promise((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(new DOMException('timeout', 'AbortError')));
      }),
    }));
    const pending = api.status('dummy-id', {}).catch((error: unknown) => error);
    await vi.advanceTimersByTimeAsync(30_000);

    const error = await pending;
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).kind).toBe('network');
  });
});
