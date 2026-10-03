<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Http;

/**
 * Normalises a client address for rate limiting (§9.2): IPv4-mapped IPv6 becomes IPv4,
 * native IPv6 is reduced to its configured prefix.
 */
final class ClientAddress
{
    public static function normalize(?string $address, int $ipv6Prefix): string
    {
        $packed = $address === null ? false : @inet_pton($address);
        if ($packed === false) {
            return 'unknown';
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        $bytes = intdiv($ipv6Prefix, 8);
        $bits = $ipv6Prefix % 8;
        $masked = substr($packed, 0, $bytes);
        if ($bits > 0) {
            $masked .= chr(ord($packed[$bytes]) & (0xff << (8 - $bits)) & 0xff);
        }
        $masked = str_pad($masked, 16, "\x00");

        return inet_ntop($masked) . '/' . $ipv6Prefix;
    }
}
