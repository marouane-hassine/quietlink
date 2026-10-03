// SPDX-License-Identifier: AGPL-3.0-or-later

/** Runs Argon2id in the dedicated same-origin worker (no blob:/data: workers). */

import argon2WorkerUrl from './argon2.worker.ts?worker&url';

export class Argon2UnavailableError extends Error {}

interface TrustedTypePolicyFactoryLike {
  createPolicy(name: string, rules: { createScriptURL(url: string): string }): { createScriptURL(url: string): unknown };
}

let policy: { createScriptURL(url: string): unknown } | null = null;

/** Worker URLs go through a Trusted Types policy accepting only same-origin build assets. */
function workerUrl(url: string): unknown {
  const factory = (globalThis as { trustedTypes?: TrustedTypePolicyFactoryLike }).trustedTypes;
  if (!factory) return url;
  policy ??= factory.createPolicy('quietlink-worker', {
    createScriptURL(candidate: string): string {
      const parsed = new URL(candidate, location.href);
      if (parsed.origin !== location.origin || !parsed.pathname.startsWith('/build/')) throw new TypeError('Untrusted worker URL');
      return parsed.href;
    },
  });
  return policy.createScriptURL(url);
}

export function argon2Supported(): boolean {
  return typeof Worker !== 'undefined' && typeof WebAssembly !== 'undefined';
}

let warm: Worker | null = null;

function spawn(): Worker {
  return new Worker(workerUrl(new URL(argon2WorkerUrl, location.href).href) as unknown as string, { type: 'module' });
}

/** Starts the worker ahead of time (passphrase field focus) so the module is loaded (§13). */
export function preloadArgon2(): void {
  if (warm === null && argon2Supported()) warm = spawn();
}

export function deriveInWorker(passphrase: string, salt: Uint8Array, m: number, t: number): Promise<Uint8Array> {
  if (!argon2Supported()) return Promise.reject(new Argon2UnavailableError());
  return new Promise((resolve, reject) => {
    const worker = warm ?? spawn();
    warm = null;
    worker.onmessage = (event: MessageEvent<{ ok: boolean; key?: Uint8Array }>) => {
      worker.terminate();
      if (event.data.ok && event.data.key) resolve(event.data.key);
      else reject(new Argon2UnavailableError());
    };
    worker.onerror = () => {
      worker.terminate();
      reject(new Argon2UnavailableError());
    };
    worker.postMessage({ passphrase, salt, m, t });
  });
}
