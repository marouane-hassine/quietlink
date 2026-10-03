<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\Challenge;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\Identifier;
use QuietLink\Encoding\Base64Url;
use QuietLink\Tests\Support\SpProtoV1Vectors as V;

/**
 * Behaviour not expressed by the shared vectors.
 */
#[CoversClass(Aad::class)]
#[CoversClass(Challenge::class)]
#[CoversClass(Ed25519::class)]
#[CoversClass(Identifier::class)]
#[CoversClass(Base64Url::class)]
final class CryptoPrimitivesTest extends TestCase
{
    public function testBase64UrlRoundTripsEveryLength(): void
    {
        for ($length = 1; $length <= 33; ++$length) {
            $bytes = random_bytes($length);
            self::assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes), $length));
        }
    }

    #[Group('EXG-CRYPTO-044')]
    public function testParsedAadExposesItsFields(): void
    {
        $vectors = V::group('aad');
        $aad = Aad::fromBytes(V::string($vectors['read-once-with-passphrase'][0], 'expected.canonical'));

        self::assertTrue($aad->readOnce);
        self::assertSame('1d', $aad->expiration);
        self::assertSame(32, strlen($aad->accessPk));
        self::assertNotNull($aad->consumePk);
        self::assertNotNull($aad->kdf);
        self::assertSame(19456, $aad->kdf->memoryKib);
        self::assertSame(2, $aad->kdf->passes);
        self::assertSame(16, strlen($aad->kdf->salt));
    }

    #[Group('EXG-CRYPTO-007')]
    public function testAppSecretShorterThan32BytesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Challenge::keyFromAppSecret(base64_encode(str_repeat("\x01", 31)));
    }

    #[Group('EXG-CRYPTO-028')]
    public function testDerivedPublicKeyIsAcceptable(): void
    {
        self::assertTrue(Ed25519::isAcceptablePublicKey(Ed25519::publicKeyFromSeed(str_repeat("\x07", 32))));
    }

    #[Group('EXG-CRYPTO-025')]
    #[Group('EXG-CRYPTO-026')]
    public function testGeneratedIdentifiersShareFingerprintsButDifferInRandomPart(): void
    {
        $accessPk = Ed25519::publicKeyFromSeed(str_repeat("\x07", 32));
        $deletionHash = hash('sha256', 'dummy', true);
        $first = Identifier::generate($accessPk, $deletionHash);
        $second = Identifier::generate($accessPk, $deletionHash);

        self::assertSame(substr($first, 0, 16), substr($second, 0, 16));
        self::assertNotSame($first, $second);
        self::assertTrue(Identifier::matchesAccessKey($first, $accessPk));
        self::assertTrue(Identifier::matchesDeletionHash($first, $deletionHash));
    }

    #[Group('EXG-READ-024')]
    public function testChallengeIssuedInTheFutureIsRefused(): void
    {
        $key = str_repeat("\x02", 32);
        $id = str_repeat("\x03", 24);
        $challenge = Challenge::issue($key, Challenge::USAGE_OPEN, $id, 1000);

        self::assertTrue(Challenge::verify($key, $challenge, Challenge::USAGE_OPEN, $id, 1060));
        self::assertFalse(Challenge::verify($key, $challenge, Challenge::USAGE_OPEN, $id, 1061));
        self::assertFalse(Challenge::verify($key, $challenge, Challenge::USAGE_OPEN, $id, 999));
    }
}
