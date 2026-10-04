<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Client;

use RuntimeException;

/**
 * Decrypted plaintext that is not a valid sp-proto/v1 envelope.
 */
final class InvalidEnvelopeException extends RuntimeException
{
}
