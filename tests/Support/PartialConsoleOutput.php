<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Standard output that accepts only a few bytes, like a pipe whose reader quit (`| head -c 4`).
 */
final class PartialConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        if (!in_array('ql-partial', stream_get_wrappers(), true)) {
            stream_wrapper_register('ql-partial', PartialStream::class);
        }
        PartialStream::$accepted = '';
        $stream = fopen('ql-partial://out', 'w');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open the partial stream.');
        }
        parent::__construct($stream, OutputInterface::VERBOSITY_VERBOSE);
        $this->stderr = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('Not supported.');
    }

    public function errors(): string
    {
        return $this->stderr instanceof BufferedOutput ? $this->stderr->fetch() : '';
    }
}

/**
 * Stream wrapper accepting 4 bytes in total, then nothing.
 */
final class PartialStream
{
    public static string $accepted = '';

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $room = 4 - strlen(self::$accepted);
        if ($room <= 0) {
            return 0;
        }
        $part = substr($data, 0, $room);
        self::$accepted .= $part;

        return strlen($part);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
