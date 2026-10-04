// SPDX-License-Identifier: AGPL-3.0-or-later

/** K_pass = Argon2id v1.3 (sp-proto/v1 §6). Runs only inside the dedicated worker in the browser. */

import { argon2id } from 'hash-wasm';
import { utf8 } from './bytes';

export async function derivePassphraseKey(passphrase: string, salt: Uint8Array, memoryKib: number, passes: number): Promise<Uint8Array> {
  const normalized = utf8.encode(passphrase.normalize('NFC'));
  try {
    return await argon2id({
      password: normalized,
      salt,
      parallelism: 1,
      iterations: passes,
      memorySize: memoryKib,
      hashLength: 32,
      outputType: 'binary',
    });
  } finally {
    normalized.fill(0);
  }
}
