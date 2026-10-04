// SPDX-License-Identifier: AGPL-3.0-or-later

/** sp-proto/v1 constants (docs/protocol/sp-proto-v1.md). */
export const HKDF_SALT = 'sp-proto/v1/hkdf';
export const INFO_ACCESS = 'sp-proto/v1/access/ed25519';
export const INFO_CONTENT = 'sp-proto/v1/content/aes-256-gcm';
export const INFO_CONSUME = 'sp-proto/v1/read-once/consume/ed25519';
export const ID_ACCESS_PREFIX = 'sp-proto/v1/id';
export const ID_DELETE_PREFIX = 'sp-proto/v1/id-delete';
export const PROOF_PREFIX = 'sp-proto/v1/proof';
export const KEY_BYTES = 32;
export const ID_BYTES = 24;
export const NONCE_BYTES = 12;
export const SALT_BYTES = 16;
export const ARGON2_MIN_M = 19456;
export const ARGON2_MAX_M = 262144;
export const ARGON2_MIN_T = 2;
export const ARGON2_MAX_T = 10;
export const ARGON2_DEFAULT_M = 65536;
export const ARGON2_DEFAULT_T = 3;
export const EXPIRATIONS = ['5m', '1h', '1d', '7d', '30d', 'never'] as const;
export type Expiration = (typeof EXPIRATIONS)[number];
