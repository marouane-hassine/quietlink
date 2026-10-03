<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\PasteId;
use QuietLink\Storage\PasteMeta;
use QuietLink\Storage\PasteRecord;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\FrozenClock;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(FilesystemPasteStore::class)]
#[CoversClass(PasteId::class)]
final class StoreEdgeCasesTest extends TestCase
{
    private TempDirectory $tmp;
    private FrozenClock $clock;
    private StorageLayout $layout;
    private FilesystemPasteStore $store;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->clock = new FrozenClock();
        $this->layout = new StorageLayout($this->tmp->path . '/pastes', $this->tmp->path . '/idempotency', $this->tmp->path . '/state');
        $this->layout->ensureDirectories();
        $this->store = new FilesystemPasteStore($this->layout, new UsageCounter($this->layout, 1 << 30, 100), $this->clock);
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    private function create(): PasteId
    {
        return $this->store->create(
            fn (PasteId $id): PasteMeta => new PasteMeta($id, 'aad', $this->clock->now(), $this->clock->now() + 3600, true, str_repeat("\x01", 32), str_repeat("\x02", 32)),
            static fn (): PasteId => PasteId::fromBytes(random_bytes(24)),
            'nonce+ciphertext',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'traversal' => ['../../../../etc/passwd/xxxxxxxxxx'];
        yield 'slash' => ['AAAAAAAAAAAAAAAA/AAAAAAAAAAAAAAA'];
        yield 'dots' => ['................................'];
        yield 'null byte' => ["AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\0"];
        yield 'standard alphabet' => ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA+'];
        yield 'too long' => [str_repeat('A', 43)];
    }

    #[DataProvider('hostileIdentifiers')]
    #[Group('EXG-STORE-001')]
    public function testOnlyCanonicalIdentifiersCanReachAPath(string $id): void
    {
        $this->expectException(InvalidEncodingException::class);
        PasteId::fromEncoded($id);
    }

    #[Group('EXG-STORE-001')]
    public function testPathsStayInsideTheRoot(): void
    {
        $id = PasteId::fromBytes(random_bytes(24));
        self::assertStringStartsWith($this->layout->rootDir . '/', $this->layout->pasteDir($id));
        self::assertStringNotContainsString('..', $this->layout->pasteDir($id));
    }

    #[Group('EXG-STORE-017')]
    public function testTransitionsRewriteOnlyTheStateFile(): void
    {
        $id = $this->create();
        $dir = $this->layout->pasteDir($id);
        $before = [fileinode($dir . '/meta.json'), fileinode($dir . '/payload.bin'), fileinode($dir . '/state.json')];
        $this->store->mutate($id, static fn (PasteRecord $r): array => [$r->state->released(), null]);
        clearstatcache();

        self::assertSame($before[0], fileinode($dir . '/meta.json'));
        self::assertSame($before[1], fileinode($dir . '/payload.bin'));
        self::assertNotSame($before[2], fileinode($dir . '/state.json'));
    }

    #[Group('EXG-STORE-026')]
    public function testAMissingLockFileMeansUnavailableAndIsNeverRecreated(): void
    {
        $id = $this->create();
        $dir = $this->layout->pasteDir($id);
        unlink($dir . '/state.lock');

        self::assertNull($this->store->find($id));
        self::assertNull($this->store->mutate($id, static fn (PasteRecord $r): array => [null, 'never']));
        self::assertFileDoesNotExist($dir . '/state.lock');
    }

    #[Group('EXG-STORE-024')]
    public function testIncompleteDirectoriesAreDetectedAndRemoved(): void
    {
        $id = $this->create();
        unlink($this->layout->pasteDir($id) . '/state.json');

        self::assertTrue($this->store->isIncomplete($id));
        self::assertTrue($this->store->removeIncomplete($id));
        self::assertDirectoryDoesNotExist($this->layout->pasteDir($id));
    }

    #[Group('EXG-STORE-034')]
    #[Group('EXG-STORE-033')]
    public function testOnlyOldUnlockedStagingDirectoriesAreOrphans(): void
    {
        $shard = $this->tmp->path . '/pastes/AA/AA';
        mkdir($shard, 0700, true);
        $old = $shard . '/.' . str_repeat('A', 32) . '.tmp-0123456789abcdef';
        $recent = $shard . '/.' . str_repeat('B', 32) . '.tmp-0123456789abcdef';
        mkdir($old);
        mkdir($recent);
        touch($old, $this->clock->now() - 7200);
        touch($recent, $this->clock->now());

        $orphans = $this->store->orphanStagingDirectories(3600);
        self::assertSame([$old], $orphans);
        self::assertTrue($this->store->removeOrphan($old));
        self::assertDirectoryDoesNotExist($old);
        self::assertDirectoryExists($recent);
    }
}
