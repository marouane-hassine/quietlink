<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use QuietLink\Cli\Prompt;

/**
 * Scripted terminal for CLI tests.
 */
final class FakePrompt implements Prompt
{
    /**
     * @param list<string> $secrets
     */
    public function __construct(
        private readonly bool $available = false,
        private array $secrets = [],
        private readonly bool $confirm = false,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function secret(string $question): string
    {
        return array_shift($this->secrets) ?? '';
    }

    public function confirm(string $question): bool
    {
        return $this->confirm;
    }
}
