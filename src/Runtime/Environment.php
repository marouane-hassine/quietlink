<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Runtime;

use InvalidArgumentException;
use QuietLink\Config\ConfigLoader;

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
        return self::withDotEnv($_SERVER + getenv(), RuntimeStatus::projectRoot() . '/.env');
    }

    /**
     * Adds APP_ENV and APP_DEBUG from the .env file when the process does not set them (shared
     * hosting, where the web server passes no variable). fromVariables() still never enables
     * debug in production.
     *
     * @param array<array-key, mixed> $variables
     *
     * @return array<array-key, mixed>
     */
    public static function withDotEnv(array $variables, string $dotEnvFile): array
    {
        $missing = array_values(array_filter(['APP_ENV', 'APP_DEBUG'], static fn (string $key): bool => !is_string($variables[$key] ?? null) || $variables[$key] === ''));

        foreach ($missing === [] ? [] : ConfigLoader::dotEnvValues($dotEnvFile, $missing) as $key => $value) {
            $variables[$key] = $value;
        }

        return $variables;
    }
}
