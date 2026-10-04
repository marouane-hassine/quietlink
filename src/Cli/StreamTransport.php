<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

/**
 * HTTP through PHP streams with TLS peer verification; no third-party client.
 */
final class StreamTransport implements Transport
{
    public function __construct(private readonly float $timeout = 30.0)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $lines = ['User-Agent: quietlink-cli', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new TransportException('PHP allow_url_fopen is disabled: enable it for the CLI (php -d allow_url_fopen=1).');
        }
        $handle = @fopen($url, 'r', false, $context);
        if ($handle === false) {
            throw new TransportException('Unable to reach the server.');
        }
        $content = stream_get_contents($handle);
        $meta = stream_get_meta_data($handle);
        fclose($handle);
        if ($content === false || $meta['timed_out']) {
            throw new TransportException('The request timed out.');
        }

        $status = 0;
        $responseHeaders = [];
        $raw = $meta['wrapper_data'];
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        if ($status === 0) {
            throw new TransportException('Invalid HTTP response.');
        }

        return new HttpResponse($status, $responseHeaders, $content);
    }
}
