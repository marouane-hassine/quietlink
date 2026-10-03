<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Crypto\Aad;
use QuietLink\Crypto\AccessProof;
use QuietLink\Crypto\Argon2id;
use QuietLink\Crypto\Challenge;
use QuietLink\Crypto\ContentCipher;
use QuietLink\Crypto\DecryptionFailedException;
use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\Identifier;
use QuietLink\Crypto\InvalidAadException;
use QuietLink\Crypto\KeyDerivation;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Tests\Support\SpProtoV1Vectors as V;

/**
 * PHP sp-proto/v1 implementation checked against the shared vectors.
 *
 * Specification: docs/protocol/sp-proto-v1.md
 */
#[CoversClass(AccessProof::class)]
#[CoversClass(Aad::class)]
#[CoversClass(Argon2id::class)]
#[CoversClass(Challenge::class)]
#[CoversClass(ContentCipher::class)]
#[CoversClass(DeletionToken::class)]
#[CoversClass(Ed25519::class)]
#[CoversClass(Identifier::class)]
#[CoversClass(KeyDerivation::class)]
#[CoversClass(Base64Url::class)]
final class SpProtoV1VectorsTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function hkdfNoPassphrase(): array
    {
        return V::group('hkdf_no_passphrase');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function hkdfWithPassphrase(): array
    {
        return V::group('hkdf_with_passphrase');
    }

    /**
     * Requirements: EXG-CRYPTO-035, EXG-CRYPTO-036, EXG-CRYPTO-064.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('hkdfNoPassphrase')]
    #[Group('EXG-CRYPTO-035')]
    #[Group('EXG-CRYPTO-036')]
    #[Group('EXG-CRYPTO-064')]
    public function testHkdfDerivationsWithoutPassphrase(array $vector): void
    {
        $kUrl = V::bytes($vector, 'input.k_url');

        self::assertSame(V::bytes($vector, 'expected.k_access_seed'), KeyDerivation::accessSeed($kUrl));
        self::assertSame(V::bytes($vector, 'expected.k_enc'), KeyDerivation::contentKey($kUrl, null));
        self::assertSame(V::bytes($vector, 'expected.k_consume_seed'), KeyDerivation::consumeSeed($kUrl, null));
        self::assertSame(
            V::bytes($vector, 'expected.access_pk'),
            Ed25519::publicKeyFromSeed(V::bytes($vector, 'expected.k_access_seed')),
        );
        self::assertSame(
            V::bytes($vector, 'expected.consume_pk'),
            Ed25519::publicKeyFromSeed(V::bytes($vector, 'expected.k_consume_seed')),
        );
    }

    /**
     * Requirements: EXG-CRYPTO-035, EXG-CRYPTO-036, EXG-CRYPTO-064.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('hkdfWithPassphrase')]
    #[Group('EXG-CRYPTO-035')]
    #[Group('EXG-CRYPTO-036')]
    #[Group('EXG-CRYPTO-064')]
    public function testHkdfDerivationsWithPassphrase(array $vector): void
    {
        $kUrl = V::bytes($vector, 'input.k_url');
        $kPass = V::bytes($vector, 'input.k_pass');

        self::assertSame(V::bytes($vector, 'expected.k_access_seed'), KeyDerivation::accessSeed($kUrl));
        self::assertSame(V::bytes($vector, 'expected.k_enc'), KeyDerivation::contentKey($kUrl, $kPass));
        self::assertSame(V::bytes($vector, 'expected.k_consume_seed'), KeyDerivation::consumeSeed($kUrl, $kPass));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function argon2idVectors(): array
    {
        return V::group('argon2id');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nfcVectors(): array
    {
        return V::group('nfc');
    }

    /**
     * Requirements: EXG-CRYPTO-029, EXG-CRYPTO-055, EXG-CRYPTO-060, EXG-CRYPTO-061.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('argon2idVectors')]
    #[Group('EXG-CRYPTO-029')]
    #[Group('EXG-CRYPTO-055')]
    #[Group('EXG-CRYPTO-060')]
    #[Group('EXG-CRYPTO-061')]
    public function testArgon2idPassphraseKey(array $vector): void
    {

        self::assertSame(
            V::bytes($vector, 'expected.k_pass'),
            Argon2id::derive(
                V::string($vector, 'input.passphrase'),
                V::bytes($vector, 'input.salt'),
                V::int($vector, 'input.m'),
                V::int($vector, 'input.t'),
            ),
        );
    }

    /**
     * Requirements: EXG-CRYPTO-029, EXG-CRYPTO-055, EXG-CRYPTO-060, EXG-CRYPTO-061.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('nfcVectors')]
    #[Group('EXG-CRYPTO-029')]
    #[Group('EXG-CRYPTO-055')]
    #[Group('EXG-CRYPTO-060')]
    #[Group('EXG-CRYPTO-061')]
    public function testPassphraseIsNormalisedToNfc(array $vector): void
    {
        $expected = V::bytes($vector, 'expected.k_pass');

        self::assertSame($expected, V::bytes($vector, 'expected.k_pass_of_precomposed'));
        self::assertSame(
            $expected,
            Argon2id::derive(
                V::bytes($vector, 'input.passphrase_utf8'),
                V::bytes($vector, 'input.salt'),
                V::int($vector, 'input.m'),
                V::int($vector, 'input.t'),
            ),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function identifierVectors(): array
    {
        return V::group('identifier');
    }

    /**
     * Requirements: EXG-CRYPTO-025, EXG-CRYPTO-033, EXG-URL-011.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('identifierVectors')]
    #[Group('EXG-CRYPTO-025')]
    #[Group('EXG-CRYPTO-033')]
    #[Group('EXG-URL-011')]
    public function testIdentifierBindsAccessKeyAndDeletionHash(array $vector): void
    {
        $accessPk = V::bytes($vector, 'input.access_pk');
        $deletionHash = V::bytes($vector, 'expected.deletion_hash');

        self::assertSame($deletionHash, hash('sha256', V::bytes($vector, 'input.deletion_token'), true));
        self::assertSame(V::bytes($vector, 'expected.a'), Identifier::accessPrefix($accessPk));
        self::assertSame(V::bytes($vector, 'expected.d'), Identifier::deletionPrefix($deletionHash));
        self::assertSame(
            V::bytes($vector, 'expected.id'),
            Identifier::compose($accessPk, $deletionHash, V::bytes($vector, 'input.r')),
        );
        self::assertSame(V::string($vector, 'expected.id_b64u'), Base64Url::encode(V::bytes($vector, 'expected.id')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function aadVectors(): array
    {
        return V::group('aad');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function aadRejectVectors(): array
    {
        return V::group('aad_reject');
    }

    /**
     * Requirements: EXG-CRYPTO-043, EXG-CRYPTO-044, EXG-CRYPTO-045, EXG-CRYPTO-046, EXG-CRYPTO-047, EXG-CRYPTO-048, EXG-CRYPTO-049, EXG-CRYPTO-062.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('aadVectors')]
    #[Group('EXG-CRYPTO-043')]
    #[Group('EXG-CRYPTO-044')]
    #[Group('EXG-CRYPTO-045')]
    #[Group('EXG-CRYPTO-046')]
    #[Group('EXG-CRYPTO-047')]
    #[Group('EXG-CRYPTO-048')]
    #[Group('EXG-CRYPTO-049')]
    #[Group('EXG-CRYPTO-062')]
    public function testAadCanonicalisation(array $vector): void
    {
        $canonical = V::string($vector, 'expected.canonical');
        $object = V::value($vector, 'input.object');
        self::assertIsArray($object);

        self::assertSame($canonical, Aad::canonicalize($object));
        self::assertSame($canonical, Aad::fromBytes($canonical)->bytes());
    }

    /**
     * Requirements: EXG-CRYPTO-043, EXG-CRYPTO-044, EXG-CRYPTO-045, EXG-CRYPTO-046, EXG-CRYPTO-047, EXG-CRYPTO-048, EXG-CRYPTO-049, EXG-CRYPTO-062.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('aadRejectVectors')]
    #[Group('EXG-CRYPTO-043')]
    #[Group('EXG-CRYPTO-044')]
    #[Group('EXG-CRYPTO-045')]
    #[Group('EXG-CRYPTO-046')]
    #[Group('EXG-CRYPTO-047')]
    #[Group('EXG-CRYPTO-048')]
    #[Group('EXG-CRYPTO-049')]
    #[Group('EXG-CRYPTO-062')]
    public function testNonCanonicalOrInvalidAadIsRejected(array $vector): void
    {

        $this->expectException(InvalidAadException::class);
        Aad::fromBytes(V::string($vector, 'input.aad'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function envelopeEncryptVectors(): array
    {
        return V::group('envelope_encrypt');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function envelopeRejectVectors(): array
    {
        return V::group('envelope_reject');
    }

    /**
     * Requirements: EXG-CRYPTO-031, EXG-CRYPTO-052, EXG-CRYPTO-068.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('envelopeEncryptVectors')]
    #[Group('EXG-CRYPTO-031')]
    #[Group('EXG-CRYPTO-052')]
    #[Group('EXG-CRYPTO-068')]
    public function testContentEncryptionRoundTrip(array $vector): void
    {
        $key = V::bytes($vector, 'input.k_enc');
        $nonce = V::bytes($vector, 'input.nonce');
        $aad = V::bytes($vector, 'input.aad_hex');
        $plaintext = V::bytes($vector, 'input.envelope_hex');
        $ciphertext = V::bytes($vector, 'expected.ciphertext');

        self::assertSame($ciphertext, ContentCipher::encrypt($key, $nonce, $aad, $plaintext));
        self::assertSame($plaintext, ContentCipher::decrypt($key, $nonce, $aad, $ciphertext));
    }

    /**
     * Requirements: EXG-CRYPTO-031, EXG-CRYPTO-052, EXG-CRYPTO-068.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('envelopeRejectVectors')]
    #[Group('EXG-CRYPTO-031')]
    #[Group('EXG-CRYPTO-052')]
    #[Group('EXG-CRYPTO-068')]
    public function testTamperedContentFailsToDecrypt(array $vector): void
    {

        $this->expectException(DecryptionFailedException::class);
        ContentCipher::decrypt(
            V::bytes($vector, 'input.k_enc'),
            V::bytes($vector, 'input.nonce'),
            V::bytes($vector, 'input.aad_hex'),
            V::bytes($vector, 'input.ciphertext'),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function challengeVectors(): array
    {
        return V::group('challenge');
    }

    /**
     * Requirements: EXG-CRYPTO-006, EXG-CRYPTO-007.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('challengeVectors')]
    #[Group('EXG-CRYPTO-006')]
    #[Group('EXG-CRYPTO-007')]
    public function testStatelessChallengeLayoutAndMac(array $vector): void
    {
        $key = Challenge::keyFromAppSecret(V::string($vector, 'input.app_secret_base64'));

        self::assertSame(V::bytes($vector, 'expected.k_challenge'), $key);
        self::assertSame(
            V::bytes($vector, 'expected.challenge'),
            Challenge::issue(
                $key,
                V::int($vector, 'input.usage'),
                V::bytes($vector, 'input.id'),
                V::int($vector, 'input.issued_at'),
                V::bytes($vector, 'input.nonce'),
            ),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function challengeVerifyVectors(): array
    {
        return V::group('challenge_verify');
    }

    /**
     * Requirements: EXG-READ-017, EXG-READ-019, EXG-READ-024.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('challengeVerifyVectors')]
    #[Group('EXG-READ-017')]
    #[Group('EXG-READ-019')]
    #[Group('EXG-READ-024')]
    public function testChallengeVerificationChecksMacUsageIdentifierAndFreshness(array $vector): void
    {
        $accept = V::value($vector, 'expected.accept');
        self::assertIsBool($accept);

        self::assertSame(
            $accept,
            Challenge::verify(
                V::bytes($vector, 'input.k_challenge'),
                V::bytes($vector, 'input.challenge'),
                V::int($vector, 'input.endpoint_usage'),
                V::bytes($vector, 'input.path_id'),
                V::int($vector, 'input.now'),
            ),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function publicKeyRejectVectors(): array
    {
        return V::group('public_key_reject');
    }

    /**
     * Requirements: EXG-CRYPTO-028.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('publicKeyRejectVectors')]
    #[Group('EXG-CRYPTO-028')]
    public function testSmallOrderOrNonCanonicalPublicKeyIsRejected(array $vector): void
    {

        self::assertFalse(Ed25519::isAcceptablePublicKey(V::bytes($vector, 'input.public_key')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function proofVectors(): array
    {
        return V::group('proof') + V::group('consume_proof');
    }

    /**
     * Requirements: EXG-CRYPTO-008, EXG-CRYPTO-069, EXG-CRYPTO-073.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('proofVectors')]
    #[Group('EXG-CRYPTO-008')]
    #[Group('EXG-CRYPTO-069')]
    #[Group('EXG-CRYPTO-073')]
    public function testEd25519ProofOverChallenge(array $vector): void
    {
        $message = V::bytes($vector, 'expected.message');
        $publicKey = V::bytes($vector, 'expected.public_key');
        $signature = V::bytes($vector, 'expected.signature');

        self::assertSame($message, AccessProof::message(V::bytes($vector, 'input.challenge')));
        self::assertSame($signature, Ed25519::sign(V::bytes($vector, 'input.seed'), $message));
        self::assertTrue(Ed25519::verify($publicKey, $message, $signature));

        $negatives = V::value($vector, 'negative');
        self::assertIsArray($negatives);
        foreach ($negatives as $negative) {
            self::assertIsArray($negative);
            /** @var array<string, mixed> $negative */
            $negMessage = array_key_exists('message', $negative) ? V::bytes($negative, 'message') : $message;
            $negSignature = array_key_exists('signature', $negative) ? V::bytes($negative, 'signature') : $signature;
            self::assertFalse(Ed25519::verify($publicKey, $negMessage, $negSignature), V::string($negative, 'name'));
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function deleteCheckVectors(): array
    {
        return V::group('delete_check');
    }

    /**
     * Requirements: EXG-API-038, EXG-CRYPTO-033.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('deleteCheckVectors')]
    #[Group('EXG-API-038')]
    #[Group('EXG-CRYPTO-033')]
    public function testDeletionTokenIsBoundToIdentifier(array $vector): void
    {
        $accept = V::value($vector, 'expected.accept');
        self::assertIsBool($accept);
        if ($accept) {
            self::assertSame(32, V::int($vector, 'expected.decoded_length'));
            self::assertSame(
                V::bytes($vector, 'expected.token_hash'),
                DeletionToken::hash(Base64Url::decode(V::string($vector, 'input.token_b64u'), 32)),
            );
        }

        self::assertSame(
            $accept,
            DeletionToken::matchesIdentifier(V::string($vector, 'input.token_b64u'), V::bytes($vector, 'input.id')),
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function base64UrlRejectVectors(): array
    {
        return V::group('base64url_reject');
    }

    /**
     * Expected decoded length of each typed field (docs/protocol/sp-proto-v1.md §3.2).
     */
    private const FIELD_LENGTHS = ['k_url' => 32, 'id' => 24, 'nonce' => 12, 'salt' => 16, 'signature' => 64];

    /**
     * Requirements: EXG-CRYPTO-053, EXG-URL-011.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('base64UrlRejectVectors')]
    #[Group('EXG-CRYPTO-053')]
    #[Group('EXG-URL-011')]
    public function testNonCanonicalBase64UrlIsRejected(array $vector): void
    {
        $field = V::string($vector, 'input.field');
        self::assertArrayHasKey($field, self::FIELD_LENGTHS);

        $this->expectException(InvalidEncodingException::class);
        Base64Url::decode(V::string($vector, 'input.value'), self::FIELD_LENGTHS[$field]);
    }
}
