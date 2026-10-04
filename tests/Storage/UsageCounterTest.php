<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use QuietLink\Tests\Support\TempDirectory;

#[CoversClass(UsageCounter::class)]
final class UsageCounterTest extends TestCase
{
    private TempDirectory $tmp;
    private UsageCounter $usage;
    private StorageLayout $layout;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $this->layout = new StorageLayout($this->tmp->path . '/pastes', $this->tmp->path . '/idempotency', $this->tmp->path . '/state');
        $this->layout->ensureDirectories();
        $this->usage = new UsageCounter($this->layout, 1 << 30, 1000);
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    /**
     * A create and a delete of the same size during the scan leave the totals unchanged, yet the
     * scan saw an inconsistent state: any change, or a creation finishing, must defer it (§9.7).
     */
    #[Group('EXG-STORE-043')]
    public function testRecomputationIsDeferredByAnyConcurrentChange(): void
    {
        $this->usage->reserve(100);
        $start = $this->usage->snapshot();
        $this->usage->reserve(50);
        $this->usage->release(50, 1);
        self::assertSame(['bytes' => 100, 'items' => 1], $this->usage->read());

        self::assertFalse($this->usage->applyRecomputation($start['generation'], 0, 0, 1790000000));
        self::assertSame(['bytes' => 100, 'items' => 1], $this->usage->read());

        $quiet = $this->usage->snapshot();
        $this->usage->committed();
        self::assertFalse($this->usage->applyRecomputation($quiet['generation'], 0, 0, 1790000000));

        $settled = $this->usage->snapshot();
        self::assertTrue($this->usage->applyRecomputation($settled['generation'], 100, 1, 1790000000));
        self::assertSame(1790000000, $this->usage->recomputedAt());
    }

    public function testReadsCountersWrittenWithoutAGeneration(): void
    {
        AtomicFile::write($this->layout->usageFile(), '{"schema_version":1,"bytes":7,"items":1,"recomputed_at":null}');

        self::assertSame(['bytes' => 7, 'items' => 1], $this->usage->read());
        self::assertSame(0, $this->usage->snapshot()['generation']);
    }

    /**
     * A deferred recomputation is retried after a pause rather than at every purge run, so a
     * busy instance does not rescan the whole store each minute (§9.7).
     */
    #[Group('EXG-STORE-043')]
    public function testDeferredRecomputationIsRetriedAfterAPause(): void
    {
        $this->usage->reserve(10);
        $this->usage->postponeRecomputation(1790000000, 600, 3600);

        self::assertSame(1790000000 + 600 - 3600, $this->usage->recomputedAt());
        self::assertSame(['bytes' => 10, 'items' => 1], $this->usage->read());
    }
}
