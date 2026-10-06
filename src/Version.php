<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink;

/**
 * Application release version (Semantic Versioning), shared by the web kernel and the CLI.
 * Independent of the protocol version (sp-proto/v1) and of storage schema versions.
 */
final class Version
{
    public const APP = '1.0.0-beta.2';
}
