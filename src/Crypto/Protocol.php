<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

/**
 * Constants of the sp-proto/v1 protocol (docs/protocol/sp-proto-v1.md).
 */
final class Protocol
{
    public const VERSION = 1;
    public const HKDF_SALT = 'sp-proto/v1/hkdf';
    public const INFO_ACCESS = 'sp-proto/v1/access/ed25519';
    public const INFO_CONTENT = 'sp-proto/v1/content/aes-256-gcm';
    public const INFO_CONSUME = 'sp-proto/v1/read-once/consume/ed25519';
    public const INFO_CHALLENGE = 'sp-proto/v1/server/challenge';
    public const ID_ACCESS_PREFIX = "sp-proto/v1/id\x00";
    public const ID_DELETE_PREFIX = "sp-proto/v1/id-delete\x00";
    public const PROOF_PREFIX = "sp-proto/v1/proof\x00";

    public const KEY_BYTES = 32;
    public const ID_BYTES = 24;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;
    public const SALT_BYTES = 16;
    public const PUBLIC_KEY_BYTES = 32;
    public const SIGNATURE_BYTES = 64;
    public const RESERVATION_ID_BYTES = 16;
    public const IDEMPOTENCY_KEY_BYTES = 16;
}
