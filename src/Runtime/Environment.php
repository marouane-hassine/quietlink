<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Runtime;

use InvalidArgumentException;

/**
 * Kernel environment and debug flag resolved from process variables.
 *
 * Debug defaults to on outside production so that the container cache is
 * rebuilt when configuration changes; it can never be enabled in production,
 * where it would expose stack traces.
 */
final readonly class Environment
{
    private function __construct(
        public string $name,
        public bool $debug,
    ) {
    }

    /**
     * @param array<array-key, mixed> $variables usually $_SERVER merged with getenv()
     */
    public static function fromVariables(array $variables): self
    {
        $name = $variables['APP_ENV'] ?? '';
        $name = is_string($name) && $name !== '' ? $name : 'prod';
        if (preg_match('/^[a-z]{1,16}$/D', $name) !== 1) {
            throw new InvalidArgumentException('APP_ENV must contain 1 to 16 lowercase letters.');
        }

        if ($name === 'prod') {
            return new self($name, false);
        }

        $flag = $variables['APP_DEBUG'] ?? null;
        $debug = is_string($flag) && $flag !== ''
            ? filter_var($flag, FILTER_VALIDATE_BOOLEAN)
            : true;

        return new self($name, $debug);
    }

    /**
     * Collects variables from $_SERVER and the process environment.
     * PHP-FPM clears the environment by default, so $_SERVER takes precedence.
     *
     * @return array<array-key, mixed>
     */
    public static function processVariables(): array
    {
        return $_SERVER + getenv();
    }
}
