<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

use LogicException;

/**
 * Typed access to a configuration tree already type-checked against the defaults.
 */
final readonly class TreeReader
{
    /**
     * @param array<array-key, mixed> $tree
     */
    public function __construct(private array $tree)
    {
    }

    public function string(string $path): string
    {
        $value = $this->value($path);

        return is_string($value) ? $value : throw new LogicException(sprintf('"%s" is not a string.', $path));
    }

    public function nullableString(string $path): ?string
    {
        $value = $this->value($path);

        return $value === null || is_string($value) ? $value : throw new LogicException(sprintf('"%s" is not a string.', $path));
    }

    public function int(string $path): int
    {
        $value = $this->value($path);

        return is_int($value) ? $value : throw new LogicException(sprintf('"%s" is not an integer.', $path));
    }

    public function bool(string $path): bool
    {
        $value = $this->value($path);

        return is_bool($value) ? $value : throw new LogicException(sprintf('"%s" is not a boolean.', $path));
    }

    /**
     * @return list<string>
     */
    public function stringList(string $path): array
    {
        $value = $this->value($path);
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException(sprintf('"%s" is not a list.', $path));
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new LogicException(sprintf('"%s" must only contain strings.', $path));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function map(string $path): array
    {
        $value = $this->value($path);

        return is_array($value) ? $value : throw new LogicException(sprintf('"%s" is not a map.', $path));
    }

    private function value(string $path): mixed
    {
        $current = $this->tree;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new LogicException(sprintf('Missing configuration key "%s".', $path));
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
