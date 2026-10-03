<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Log\JsonLogger;

#[CoversClass(JsonLogger::class)]
final class JsonLoggerTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private static function capture(string $message, array $context, string $level = 'info'): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        (new JsonLogger('info', $stream))->log($level, $message, $context);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    #[Group('EXG-OBS-002')]
    #[Group('EXG-SEC-069')]
    public function testSecretsIdentifiersAndUrlsNeverReachTheLog(): void
    {
        $line = self::capture('Matched route {route} for https://paste.example.test/p/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA#BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', [
            'route_parameters' => ['id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
            'X-Deletion-Token' => 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC',
            'Idempotency-Key' => 'DDDDDDDDDDDDDDDDDDDDDD',
            'route' => 'api_open',
            'status' => 404,
        ]);

        foreach (['AAAAAAAA', 'BBBBBBBB', 'CCCCCCCC', 'DDDDDDDD', 'https://', 'route_parameters'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $line);
        }
        $record = json_decode($line, true);
        self::assertIsArray($record);
        self::assertSame('api_open', $record['route']);
        self::assertSame(404, $record['status']);
    }

    public function testLevelThresholdIsApplied(): void
    {
        self::assertSame('', self::capture('debug detail', [], 'debug'));
    }
}
