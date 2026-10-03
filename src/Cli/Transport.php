<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * Sends one HTTP request. Throws TransportException on network failure or timeout.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
