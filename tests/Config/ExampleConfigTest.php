<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Config\ConfigLoader;

#[CoversNothing]
final class ExampleConfigTest extends TestCase
{
    #[Group('EXG-CONF-006')]
    #[Group('EXG-CONF-011')]
    public function testExampleMatchesDefaults(): void
    {
        $example = require dirname(__DIR__, 2) . '/config/config.php.example';
        self::assertIsArray($example);
        $defaults = ConfigLoader::defaults();
        $defaults['app']['public_url'] = 'https://quietlink.example.test';

        self::assertSame($defaults, $example);
    }
}
