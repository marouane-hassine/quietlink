<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Crypto;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuietLink\Tests\Support\SpProtoV1Vectors;

/**
 * Structural checks on the shared sp-proto/v1 vector file.
 *
 * Requirements: EXG-CRYPTO-014, EXG-CRYPTO-022, EXG-CRYPTO-037.
 */
#[CoversNothing]
final class VectorFileTest extends TestCase
{
    public function testVectorFileTargetsProtocolV1(): void
    {
        self::assertSame('sp-proto/v1', SpProtoV1Vectors::document()['protocol'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredGroups(): iterable
    {
        foreach (
            [
                'hkdf_no_passphrase', 'argon2id', 'hkdf_with_passphrase', 'nfc', 'pkcs8', 'identifier',
                'aad', 'aad_reject', 'envelope_encrypt', 'envelope_reject', 'challenge', 'proof',
                'consume_proof', 'delete_check', 'base64url_reject',
            ] as $group
        ) {
            yield $group => [$group];
        }
    }

    #[DataProvider('requiredGroups')]
    public function testRequiredGroupIsPresentAndNotEmpty(string $group): void
    {
        self::assertNotEmpty(SpProtoV1Vectors::group($group));
    }

    public function testVectorFileContainsNoPrivateKeyMaterialMarkers(): void
    {
        $json = file_get_contents(SpProtoV1Vectors::PATH);
        self::assertIsString($json);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $json);
        self::assertStringContainsString('example.test', $json, 'URLs must use a reserved example domain.');
    }
}
