<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Tests\Support\TempDirectory;

/**
 * The configuration errors are available for the log (config_invalid event), as text that
 * never contains a value: shared hosts have no other way to see why the site answers 503.
 */
#[CoversClass(RuntimeStatus::class)]
final class RuntimeStatusTest extends TestCase
{
    #[Group('EXG-OPS-011')]
    public function testConfigurationErrorsAreReportedWithoutValues(): void
    {
        $tmp = new TempDirectory();
        try {
            mkdir($tmp->path . '/config');
            file_put_contents($tmp->path . '/config/config.php', "<?php\nreturn ['app' => ['public_url' => 'https://paste.example.test'], 'paste' => ['allowed_expirations' => ['5m', '1y']]];\n");
            $_SERVER['QUIETLINK_APP_SECRET'] = base64_encode(str_repeat("\x42", 32));
            $status = new RuntimeStatus($tmp->path . '/config');

            self::assertSame('config_invalid', $status->notReadyReason());
            $errors = $status->configErrors();
            self::assertNotSame([], $errors);
            self::assertStringContainsString('paste.allowed_expirations', implode("\n", $errors));
            self::assertStringNotContainsString('1y', implode("\n", $errors));
        } finally {
            unset($_SERVER['QUIETLINK_APP_SECRET']);
            $tmp->remove();
        }
    }
}
