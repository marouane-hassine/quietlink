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
    #[Group('EXG-OBS-003')]
    #[Group('EXG-OBS-004')]
    #[Group('EXG-OBS-008')]
    #[Group('EXG-URL-003')]
    #[Group('EXG-URL-016')]
    #[Group('EXG-CACHE-004')]
    #[Group('EXG-TEST-101')]
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

    #[Group('EXG-OBS-010')]
    public function testLevelThresholdIsApplied(): void
    {
        self::assertSame('', self::capture('debug detail', [], 'debug'));
    }

    /**
     * Symfony's router logs every matched route at info: it is debug noise, not the documented
     * request line, and is dropped at the default level.
     */
    #[Group('EXG-OBS-005')]
    public function testRouterMatchLinesAreDebugLevel(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $logger = new \QuietLink\Log\JsonLogger(\Psr\Log\LogLevel::INFO, $stream);
        $logger->info('Matched route "{route}".', ['route' => 'healthz']);
        $logger->info('request', ['route' => 'healthz', 'status' => 200]);
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream)), static fn (string $line): bool => $line !== ""));

        self::assertCount(1, $lines);
        self::assertStringContainsString('"message":"request"', $lines[0]);
    }

    /**
     * Shared hosting: log.file writes the same JSON lines to a file outside public/, rotated by
     * size (one archive), created with restricted permissions; falls back to stderr when the
     * file cannot be opened (read-only container).
     */
    #[Group('EXG-OPS-010')]
    public function testFileOutputRotatesBySize(): void
    {
        $dir = sys_get_temp_dir() . '/ql-log-' . bin2hex(random_bytes(4));
        $file = $dir . '/log/quietlink.log';
        try {
            $logger = new JsonLogger('info', null, $file, 300);
            $logger->info('first line', ['event' => 'boot_ok']);
            self::assertFileExists($file);
            self::assertSame(0640, fileperms($file) & 0777);
            $line = json_decode(trim((string) file_get_contents($file)), true);
            self::assertIsArray($line);
            self::assertSame('first line', $line['message']);
            self::assertSame('boot_ok', $line['event']);
            for ($i = 0; $i < 10; ++$i) {
                $logger->info('line ' . $i);
            }
            self::assertFileExists($file . '.1');
            self::assertLessThan(600, filesize($file));
            self::assertFileDoesNotExist($file . '.2');
        } finally {
            foreach ([$file, $file . '.1'] as $path) {
                @unlink($path);
            }
            @rmdir($dir . '/log');
            @rmdir($dir);
        }
    }

    /**
     * Another worker (or logger) rotated the file: a handle still open on the archive must not
     * keep writing there, where the next rotation would erase the lines.
     */
    #[Group('EXG-OPS-010')]
    public function testAnotherLoggerRotationIsFollowed(): void
    {
        $dir = sys_get_temp_dir() . '/ql-log-' . bin2hex(random_bytes(4));
        $file = $dir . '/quietlink.log';
        try {
            $first = new JsonLogger('info', null, $file, 400);
            $second = new JsonLogger('info', null, $file, 400);
            $first->info('opened');
            for ($i = 0; $i < 12; ++$i) {
                $second->info('filler ' . $i);
            }
            $first->info('marker after rotation');
            self::assertStringContainsString('marker after rotation', (string) file_get_contents($file));
        } finally {
            foreach ([$file, $file . '.1'] as $path) {
                @unlink($path);
            }
            @rmdir($dir);
        }
    }

    /**
     * Two workers deciding to rotate at once: the second must not rename the fresh file over the
     * archive the first one just made (which would lose the whole archive).
     */
    #[Group('EXG-OPS-010')]
    public function testSimultaneousRotationsKeepTheArchive(): void
    {
        $dir = sys_get_temp_dir() . '/ql-log-' . bin2hex(random_bytes(4));
        $file = $dir . '/quietlink.log';
        try {
            $other = new JsonLogger('info', null, $file, 400);
            $fired = false;
            $logger = new JsonLogger('info', null, $file, 400, static function () use ($other, &$fired): void {
                if (!$fired) {
                    $fired = true;
                    // The other worker rotates first, then writes one line to the new file.
                    $other->info('other worker line');
                }
            });
            // Fill to just over the limit: the next write by either logger rotates.
            for ($i = 0; (int) @filesize($file) < 400; ++$i) {
                clearstatcache();
                $other->info('filler line ' . $i);
                clearstatcache();
            }
            $logger->info('last line');
            self::assertTrue($fired);
            self::assertStringContainsString('filler line 0', (string) file_get_contents($file . '.1'));
            self::assertStringContainsString('last line', (string) file_get_contents($file));
        } finally {
            foreach ([$file, $file . '.1', $file . '.lock'] as $path) {
                @unlink($path);
            }
            @rmdir($dir);
        }
    }

    #[Group('EXG-OPS-010')]
    public function testUnwritableFileFallsBackToTheStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        (new JsonLogger('info', $stream, '/proc/quietlink-not-writable/x.log'))->warning('still logged');
        rewind($stream);
        self::assertStringContainsString('still logged', (string) stream_get_contents($stream));
    }
}
