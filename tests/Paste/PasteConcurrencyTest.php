<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Paste;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Client\ClientCrypto;
use QuietLink\Clock\SystemClock;
use QuietLink\Encoding\Base64Url;
use QuietLink\Paste\PasteService;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\IdempotencyStore;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;

#[CoversClass(PasteService::class)]
#[CoversClass(FilesystemPasteStore::class)]
final class PasteConcurrencyTest extends TestCase
{
    private const READERS = 8;

    #[Group('EXG-LIFE-010')]
    #[Group('EXG-STORE-002')]
    #[Group('EXG-STORE-025')]
    #[Group('EXG-TEST-037')]
    #[Group('EXG-TEST-047')]
    public function testOnlyOneConcurrentReaderObtainsTheReservation(): void
    {
        $tmp = new TempDirectory();
        try {
            $config = TestInstance::config($tmp);
            $layout = TestInstance::layout($config);
            $clock = new SystemClock();
            $stateFiles = new StateFiles($layout);
            $stateFiles->writeHealth($clock->now(), 1 << 40, 90);
            $service = new PasteService(
                $config,
                new FilesystemPasteStore($layout, new UsageCounter($layout, $config->storage->maxTotalBytes, $config->storage->maxItems), $clock),
                new IdempotencyStore($layout, $clock),
                $stateFiles,
                $clock,
                static fn (): int => PHP_INT_MAX,
            );
            $prepared = ClientCrypto::prepare('{"format":"plain","language":null,"template":null,"text":"dummy","v":1}', '1h', true);
            $id = $service->create($prepared->json(), $prepared->idempotencyKey)['id']->encoded();

            $barrier = $tmp->path . '/go';
            $processes = [];
            for ($i = 0; $i < self::READERS; ++$i) {
                $challenge = $service->challenge($id, '{"usage":"open"}');
                $bodyFile = $tmp->path . '/body-' . $i . '.json';
                file_put_contents($bodyFile, json_encode([
                    'challenge' => $challenge,
                    'access_pk' => ClientCrypto::accessPublicKey($prepared->urlKey),
                    'signature' => ClientCrypto::prove($prepared->accessSeed, $challenge),
                    'reservation_id' => Base64Url::encode(random_bytes(16)),
                ], JSON_THROW_ON_ERROR));
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/fixtures/concurrent-open.php', $tmp->path, $id, $bodyFile, $barrier],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            touch($barrier);

            $outcomes = [];
            foreach ($processes as [$process, $pipes]) {
                $outcomes[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
                proc_close($process);
            }
            sort($outcomes);

            self::assertSame(['reserved'], array_values(array_unique(array_filter($outcomes, static fn (string $o): bool => $o !== 'conflict'))));
            self::assertCount(self::READERS - 1, array_filter($outcomes, static fn (string $o): bool => $o === 'conflict'));
        } finally {
            $tmp->remove();
        }
    }
}
