<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Crypto;

use JsonException;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use stdClass;

/**
 * Canonical AAD of sp-proto/v1 §8: RFC 8785 subset, validated field by field.
 */
final readonly class Aad
{
    public const ALG = 'A256GCM';
    public const KDF_ALG = 'argon2id13';
    public const EXPIRATIONS = ['5m', '1h', '1d', '7d', '30d', 'never'];
    public const MAX_BYTES = 4096;

    private const KEYS = ['access_pk', 'alg', 'consume_pk', 'expiration', 'kdf', 'read_once', 'v'];
    private const KDF_KEYS = ['alg', 'm', 'p', 'salt', 't'];

    private function __construct(
        private string $bytes,
        public bool $readOnce,
        public string $expiration,
        public string $accessPk,
        public ?string $consumePk,
        public ?KdfParameters $kdf,
    ) {
    }

    /**
     * Serialises an AAD object (associative arrays) canonically, without validating it.
     *
     * @param array<array-key, mixed> $object
     */
    public static function canonicalize(array $object): string
    {
        return json_encode(self::sortKeys($object), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidAadException
     */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || preg_match('/^[\x20-\x7e]+$/D', $bytes) !== 1) {
            throw new InvalidAadException('AAD must be 1 to 4096 printable ASCII bytes.');
        }

        try {
            $object = json_decode($bytes, false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidAadException('AAD is not valid JSON.');
        }
        if (!$object instanceof stdClass) {
            throw new InvalidAadException('AAD must be a JSON object.');
        }

        $fields = self::members($object, self::KEYS);
        if ($fields['v'] !== Protocol::VERSION || $fields['alg'] !== self::ALG) {
            throw new InvalidAadException('Unsupported AAD version or algorithm.');
        }
        if (!is_bool($fields['read_once'])) {
            throw new InvalidAadException('read_once must be a boolean.');
        }
        if (!is_string($fields['expiration']) || !in_array($fields['expiration'], self::EXPIRATIONS, true)) {
            throw new InvalidAadException('Unknown expiration.');
        }

        $accessPk = self::publicKey($fields['access_pk']);
        $consumePk = $fields['consume_pk'] === null ? null : self::publicKey($fields['consume_pk']);
        if ($fields['read_once'] !== ($consumePk !== null)) {
            throw new InvalidAadException('read_once must be true if and only if consume_pk is set.');
        }

        $kdf = null;
        if ($fields['kdf'] !== null) {
            if (!$fields['kdf'] instanceof stdClass) {
                throw new InvalidAadException('kdf must be an object or null.');
            }
            $kdf = self::kdf($fields['kdf']);
        }

        $canonical = json_encode(self::sortKeys(self::toArray($object)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals($canonical, $bytes)) {
            throw new InvalidAadException('AAD is not in canonical form.');
        }

        return new self($bytes, $fields['read_once'], $fields['expiration'], $accessPk, $consumePk, $kdf);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function members(stdClass $object, array $keys): array
    {
        $members = [];
        foreach (get_object_vars($object) as $name => $value) {
            $members[(string) $name] = $value;
        }
        $names = array_keys($members);
        sort($names, SORT_STRING);
        if ($names !== $keys) {
            throw new InvalidAadException('Missing or unknown AAD member.');
        }

        return $members;
    }

    private static function kdf(stdClass $object): KdfParameters
    {
        $fields = self::members($object, self::KDF_KEYS);
        if ($fields['alg'] !== self::KDF_ALG) {
            throw new InvalidAadException('Unsupported KDF.');
        }
        foreach (['m', 'p', 't'] as $name) {
            if (!is_int($fields[$name])) {
                throw new InvalidAadException('KDF parameters must be integers.');
            }
        }
        /** @var int $m */
        $m = $fields['m'];
        /** @var int $t */
        $t = $fields['t'];
        /** @var int $p */
        $p = $fields['p'];
        if (!Argon2id::parametersAreValid($m, $t, $p)) {
            throw new InvalidAadException('KDF parameters are out of bounds.');
        }

        return new KdfParameters($m, $t, self::binary($fields['salt'], Protocol::SALT_BYTES));
    }

    private static function publicKey(mixed $value): string
    {
        return self::binary($value, Protocol::PUBLIC_KEY_BYTES);
    }

    private static function binary(mixed $value, int $length): string
    {
        if (!is_string($value)) {
            throw new InvalidAadException('Binary AAD members must be strings.');
        }
        try {
            return Base64Url::decode($value, $length);
        } catch (InvalidEncodingException) {
            throw new InvalidAadException('Invalid binary AAD member.');
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function toArray(stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = $value instanceof stdClass ? self::toArray($value) : $value;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $object
     *
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $object): array
    {
        ksort($object, SORT_STRING);
        foreach ($object as $key => $value) {
            if (is_array($value)) {
                $object[$key] = self::sortKeys($value);
            }
        }

        return $object;
    }
}
