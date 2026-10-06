<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Client\Envelope;
use QuietLink\Client\InvalidEnvelopeException;

/**
 * Strict envelope parsing of the CLI, aligned with frontend/src/crypto/envelope.ts (sp-proto/v1 §7.1).
 */
#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    private const VALID = '{"format":"markdown","language":null,"template":"credentials","text":"dummy","v":1}';

    #[Group('EXG-CRYPTO-038')]
    #[Group('EXG-CRYPTO-039')]
    public function testValidEnvelopesAreParsed(): void
    {
        $envelope = Envelope::parse(self::VALID);
        self::assertSame(['format' => 'markdown', 'language' => null, 'template' => 'credentials', 'text' => 'dummy'], $envelope);

        $reordered = Envelope::parse('{"v":1,"text":"a\\u2028b","template":null,"language":"c++","format":"code"}');
        self::assertSame("a\u{2028}b", $reordered['text']);
        self::assertSame('c++', $reordered['language']);

        // Member-like sequences inside a string are not member names.
        $tricky = Envelope::parse(<<<'JSON'
            {"format":"plain","language":null,"template":null,"text":"\\\"v\\\":1, \"v\": 1, \"text\" :","v":1}
            JSON);
        self::assertSame('\\"v\\":1, "v": 1, "text" :', $tricky['text']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvelopes(): iterable
    {
        $replace = static fn (string $search, string $replacement): string => str_replace($search, $replacement, self::VALID);
        yield 'not json' => ['{"format":'];
        yield 'array' => ['["plain"]'];
        yield 'string' => ['"plain"'];
        yield 'duplicate key' => [$replace('"v":1}', '"v":1,"v":1}')];
        yield 'duplicate text' => [$replace('"text":"dummy"', '"text":"dummy","text":"other"')];
        yield 'escaped duplicate key' => [$replace('"v":1}', '"v":1,"v":1}')];
        yield 'unknown key' => [$replace('"v":1}', '"v":1,"extra":null}')];
        yield 'missing key' => [$replace('"language":null,', '')];
        yield 'version 2' => [$replace('"v":1}', '"v":2}')];
        yield 'version string' => [$replace('"v":1}', '"v":"1"}')];
        yield 'version float' => [$replace('"v":1}', '"v":1.5}')];
        yield 'unknown format' => [$replace('"markdown"', '"html"')];
        yield 'null format' => [$replace('"markdown"', 'null')];
        yield 'text not a string' => [$replace('"dummy"', '42')];
        yield 'nested text' => [$replace('"dummy"', '{"x":1}')];
        yield 'uppercase language' => [$replace('"language":null', '"language":"PHP"')];
        yield 'empty language' => [$replace('"language":null', '"language":""')];
        yield 'long language' => [$replace('"language":null', '"language":"' . str_repeat('a', 33) . '"')];
        yield 'template with digits' => [$replace('"credentials"', '"cred3"')];
        yield 'long template' => [$replace('"credentials"', '"' . str_repeat('a', 33) . '"')];
        yield 'invalid utf-8' => [$replace('dummy', "dum\xffmy")];
        yield 'lone surrogate' => [$replace('dummy', '\ud800')];
    }

    #[DataProvider('invalidEnvelopes')]
    #[Group('EXG-CRYPTO-038')]
    #[Group('EXG-CRYPTO-039')]
    public function testInvalidEnvelopesAreRejected(string $json): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        Envelope::parse($json);
    }

    /**
     * Large texts with many escapes (logs, code) are parsed in linear time: a regular expression
     * reached PCRE's backtrack limit and the CLI reported valid content as invalid.
     */
    #[Group('EXG-CLI-001')]
    public function testLargeTextsWithManyEscapesAreParsed(): void
    {
        $text = str_repeat("x\n", 1_000_000);
        $json = json_encode(['format' => 'plain', 'language' => null, 'template' => null, 'text' => $text, 'v' => 1], JSON_THROW_ON_ERROR);
        self::assertSame($text, Envelope::parse($json)['text']);

        $this->expectException(InvalidEnvelopeException::class);
        Envelope::parse('{"format":"plain","language":null,"template":null,"text":"' . str_repeat('a\\n', 1000) . '","text":"b","v":1}');
    }
}
