<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\EventSubscriber\SecurityHeadersSubscriber;

/**
 * Static checks of the shipped deployment files.
 */
#[CoversNothing]
final class DeploymentConfigTest extends TestCase
{
    private static function file(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($content);

        return $content;
    }

    #[Group('EXG-SEC-047')]
    #[Group('EXG-SEC-048')]
    #[Group('EXG-SEC-087')]
    #[Group('EXG-SEC-049')]
    #[Group('EXG-TEST-062')]
    #[Group('EXG-TEST-104')]
    public function testWorkerPolicyIsThePagePolicyPlusWasm(): void
    {
        $nginx = self::file('docker/nginx/default.conf');
        $page = SecurityHeadersSubscriber::contentSecurityPolicy(false, false);
        $worker = str_replace("script-src 'self'", "script-src 'self' 'wasm-unsafe-eval'", $page);

        self::assertStringContainsString('add_header Content-Security-Policy "' . $worker . '" always;', $nginx);
        self::assertStringNotContainsString('wasm-unsafe-eval', $page);
    }

    #[Group('EXG-OBS-005')]
    #[Group('EXG-CACHE-008')]
    public function testNginxCollectsNoAccessLogAndTrustsNoForwardedHeader(): void
    {
        $nginx = self::file('docker/nginx/default.conf');

        self::assertStringContainsString('access_log off;', $nginx);
        self::assertStringContainsString('client_max_body_size 1408k;', $nginx);
        self::assertStringContainsString('immutable', $nginx);
        self::assertStringNotContainsString('fastcgi_param HTTPS', $nginx);
    }

    #[Group('EXG-DEPLOY-001')]
    public function testComposeHasNoDatabaseAndRunsHardened(): void
    {
        $compose = self::file('compose.yaml');

        self::assertDoesNotMatchRegularExpression('/image:\s*(postgres|mysql|mariadb|redis|valkey|mongo)/i', $compose);
        self::assertStringContainsString('read_only: true', $compose);
        self::assertStringContainsString('cap_drop: [ALL]', $compose);
        self::assertStringContainsString('no-new-privileges:true', $compose);
        self::assertStringContainsString('app:purge-expired', $compose);
        self::assertStringContainsString('USER 10001:10001', self::file('docker/php/Dockerfile'));
    }
}
