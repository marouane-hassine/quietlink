<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Crypto;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static check: secret-derived values (hashes, tokens, signatures, challenges, MACs, seeds,
 * keys) are compared with hash_equals() only, never with == or === (§7.5).
 */
#[CoversNothing]
final class ConstantTimeComparisonTest extends TestCase
{
    private const SECRET_NAME = '[A-Za-z_>$-]*(?:hash|token|signature|challenge|secret|mac|seed|key)[A-Za-z_]*';
    /** Comparisons with a literal, a length or a count leak nothing secret. */
    private const HARMLESS = '/(?:===|!==|==|!=)\s*(?:null|true|false|\'\'|\d)|(?:null|true|false|\d)\s*(?:===|!==|==|!=)|strlen\(|count\(|array_keys\(|\$keys\b|\$names\b/i';

    private static function pattern(): string
    {
        $operand = self::SECRET_NAME . '(?:\([^()]*\))?(?:\[[^\]]*\])?';

        return '/(?:\$' . $operand . '\s*(?:===|!==|==|!=)(?!=))|(?:(?:===|!==|==|!=)\s*\$' . $operand . ')/i';
    }

    #[Group('EXG-CRYPTO-012')]
    public function testSecretValuesAreNeverComparedWithEqualityOperators(): void
    {
        $pattern = self::pattern();
        $offences = [];
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname());
            foreach ($lines === false ? [] : $lines as $number => $line) {
                if (preg_match($pattern, $line) === 1 && preg_match(self::HARMLESS, $line) !== 1) {
                    $offences[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        self::assertSame([], $offences, 'use hash_equals() for secret-derived values');
    }

    #[Group('EXG-CRYPTO-012')]
    public function testThePatternDetectsAPlainComparison(): void
    {
        $pattern = self::pattern();

        self::assertSame(1, preg_match($pattern, 'if ($record->meta->deletionTokenHash === $tokenHash) {'));
        self::assertSame(1, preg_match($pattern, 'return $signature == $expected;'));
        self::assertSame(0, preg_match($pattern, 'return hash_equals($expectedMac, $mac);'));
    }
}
