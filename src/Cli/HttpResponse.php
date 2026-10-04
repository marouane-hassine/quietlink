<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Minimal HTTP response seen by the CLI.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new CliException('The server returned an invalid response.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function string(string $key): string
    {
        $value = $this->json()[$key] ?? null;

        return is_string($value) ? $value : throw new CliException('The server returned an incomplete response.');
    }
}
