// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Dedicated Argon2id worker. Its HTTP response carries a CSP allowing 'wasm-unsafe-eval';
 * the page policy never does (§7.5).
 */

import { derivePassphraseKey } from './argon2';

interface Request {
  passphrase: string;
  salt: Uint8Array;
  m: number;
  t: number;
}

self.onmessage = async (event: MessageEvent<Request>) => {
  const { passphrase, salt, m, t } = event.data;
  try {
    const key = await derivePassphraseKey(passphrase, salt, m, t);
    (self as unknown as Worker).postMessage({ ok: true, key }, [key.buffer]);
  } catch {
    (self as unknown as Worker).postMessage({ ok: false });
  }
};
