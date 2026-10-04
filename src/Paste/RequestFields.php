<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Paste;

use JsonException;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;

/**
 * Strict validation of JSON request bodies: exact member set, base64url fields of fixed length.
 */
final readonly class RequestFields
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @throws InvalidRequestException
     */
    public static function parse(string $json, array $required, array $optional = []): self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidRequestException('Body is not valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidRequestException('Body must be a JSON object.');
        }
        $keys = array_map('strval', array_keys($data));
        if (array_diff($required, $keys) !== [] || array_diff($keys, [...$required, ...$optional]) !== []) {
            throw new InvalidRequestException('Unexpected or missing members.');
        }
        /** @var array<string, mixed> $data */
        return new self($data);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->fields) && $this->fields[$name] !== null;
    }

    /**
     * @throws InvalidRequestException
     */
    public function string(string $name): string
    {
        $value = $this->fields[$name] ?? null;

        return is_string($value) ? $value : throw new InvalidRequestException(sprintf('"%s" must be a string.', $name));
    }

    /**
     * @throws InvalidRequestException
     */
    public function binary(string $name, ?int $length = null, ?int $maxLength = null): string
    {
        try {
            $bytes = Base64Url::decode($this->string($name), $length);
        } catch (InvalidEncodingException) {
            throw new InvalidRequestException(sprintf('"%s" is not canonical base64url of the expected length.', $name));
        }
        if ($maxLength !== null && strlen($bytes) > $maxLength) {
            throw new InvalidRequestException(sprintf('"%s" is too large.', $name));
        }

        return $bytes;
    }
}
