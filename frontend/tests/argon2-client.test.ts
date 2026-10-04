// SPDX-License-Identifier: AGPL-3.0-or-later
// @vitest-environment jsdom
// Requirements: EXG-PERF-001, EXG-PERF-002, EXG-SEC-049.

import { afterEach, describe, expect, it, vi } from 'vitest';

class FakeWorker {
  static created: string[] = [];
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: (() => void) | null = null;
  constructor(url: string) {
    FakeWorker.created.push(url);
  }
  postMessage(): void {
    queueMicrotask(() => this.onmessage?.({ data: { ok: true, key: new Uint8Array(32) } } as MessageEvent));
  }
  terminate(): void {}
}

afterEach(() => {
  vi.unstubAllGlobals();
  FakeWorker.created = [];
});

describe('Argon2id worker client', () => {
  it('preloads one same-origin module worker and reuses it for the derivation', async () => {
    vi.stubGlobal('Worker', FakeWorker);
    const { deriveInWorker, preloadArgon2 } = await import('../src/crypto/argon2-client');
    preloadArgon2();
    preloadArgon2();
    expect(FakeWorker.created).toHaveLength(1);
    expect(new URL(FakeWorker.created[0] as string).origin).toBe(location.origin);
    await expect(deriveInWorker('dummy', new Uint8Array(16), 19456, 2)).resolves.toHaveLength(32);
    expect(FakeWorker.created).toHaveLength(1);
  });

  it('drops a preloaded worker that failed to load instead of waiting on it forever', async () => {
    vi.resetModules();
    let failNext = true;
    class FlakyWorker extends FakeWorker {
      constructor(url: string) {
        super(url);
        if (failNext) {
          failNext = false;
          // Script fetch failed (offline, stale chunk after a redeploy): the error comes before
          // anyone listens for messages, and the worker never answers.
          setTimeout(() => this.onerror?.(), 0);
          this.postMessage = () => undefined;
        }
      }
    }
    vi.stubGlobal('Worker', FlakyWorker);
    const { deriveInWorker, preloadArgon2 } = await import('../src/crypto/argon2-client');
    preloadArgon2();
    await new Promise((resolve) => setTimeout(resolve, 10));

    await expect(deriveInWorker('dummy', new Uint8Array(16), 19456, 2)).resolves.toHaveLength(32);
    expect(FakeWorker.created).toHaveLength(2);
  });
});
