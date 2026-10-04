<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Interactive questions asked on the controlling terminal, never on stdin.
 */
interface Prompt
{
    public function isAvailable(): bool;

    public function secret(string $question): string;

    public function confirm(string $question): bool;
}
