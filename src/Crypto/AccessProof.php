<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

/**
 * Ed25519 proof over a challenge: message = "sp-proto/v1/proof" ‖ 0x00 ‖ challenge (sp-proto/v1 §10.2).
 */
final class AccessProof
{
    public static function message(string $challenge): string
    {
        return Protocol::PROOF_PREFIX . $challenge;
    }

    public static function verify(string $publicKey, string $challenge, string $signature): bool
    {
        return Ed25519::verify($publicKey, self::message($challenge), $signature);
    }
}
