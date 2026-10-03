// SPDX-License-Identifier: AGPL-3.0-or-later

/** Runs Argon2id in the dedicated same-origin worker (no blob:/data: workers). */

export class Argon2UnavailableError extends Error {}

export function argon2Supported(): boolean {
  return typeof Worker !== 'undefined' && typeof WebAssembly !== 'undefined';
}

export function deriveInWorker(passphrase: string, salt: Uint8Array, m: number, t: number): Promise<Uint8Array> {
  if (!argon2Supported()) return Promise.reject(new Argon2UnavailableError());
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL('./argon2.worker.ts', import.meta.url), { type: 'module' });
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
