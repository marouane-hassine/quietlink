// SPDX-License-Identifier: AGPL-3.0-or-later
// Requirements (Web Crypto Ed25519 used only if conformant, §8.4): EXG-CRYPTO-071, EXG-TEST-088.

import { afterEach, describe, expect, it, vi } from 'vitest';

describe('Ed25519 implementation choice', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.resetModules();
  });

  it('uses Web Crypto when it reproduces the RFC 8032 public key and signature', async () => {
    const { ed25519 } = await import('../src/crypto/ed25519');
    expect((await ed25519()).name).toBe('webcrypto');
  });

  it('falls back to the bundled implementation when Web Crypto signs differently', async () => {
    vi.spyOn(crypto.subtle, 'sign').mockResolvedValue(new Uint8Array(64).buffer);
    const { ed25519 } = await import('../src/crypto/ed25519');
    expect((await ed25519()).name).toBe('noble');
  });
});
