<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console output with separate in-memory stdout and stderr.
 */
final class BufferedConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(OutputInterface::VERBOSITY_VERBOSE);
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
        throw new \LogicException('Sections are not supported in tests.');
    }

    public function errors(): string
    {
        return $this->stderr instanceof BufferedOutput ? $this->stderr->fetch() : '';
    }
}
