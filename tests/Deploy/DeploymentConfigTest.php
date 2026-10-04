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

    /**
     * Error responses (a 404 during an upgrade) must not be cached as immutable, and nginx must
     * not add security headers to PHP responses that already carry them: a doubled
     * Cross-Origin-Resource-Policy value is invalid and ignored by browsers.
     */
    #[Group('EXG-CACHE-008')]
    #[Group('EXG-SEC-058')]
    public function testNginxNeitherCachesErrorsNorDuplicatesPhpHeaders(): void
    {
        $nginx = self::file('docker/nginx/default.conf');
        $serverLevel = substr($nginx, 0, (int) strpos($nginx, 'location'));

        self::assertDoesNotMatchRegularExpression('/immutable"\s+always/', $nginx);
        self::assertStringNotContainsString('add_header', $serverLevel);
    }

    /**
     * nginx's error log records the client address and the request line (paste identifiers) for
     * client errors such as 413: only critical errors are logged (ADR-0005, §7.5).
     */
    #[Group('EXG-OBS-005')]
    public function testNginxErrorLogCollectsNoClientRequest(): void
    {
        // crit entries still carry "client: <address>, request: ..." (disk full while spooling a
        // body, for example): only emerg, which concerns startup and the configuration, is kept.
        self::assertMatchesRegularExpression('/^\s*error_log\s+\S+\s+emerg;/m', self::file('docker/nginx/default.conf'));
    }

    /**
     * Request bodies above the in-memory buffer and large responses are spooled to /tmp: the web
     * container's tmpfs must hold many concurrent maximum-size bodies, or creations fail.
     */
    #[Group('EXG-SEC-058')]
    #[Group('EXG-OPS-007')]
    public function testNginxSpoolSpaceHoldsManyConcurrentBodies(): void
    {
        $compose = self::file('compose.yaml');
        $web = substr($compose, (int) strpos($compose, "  web:\n"));
        self::assertSame(1, preg_match('#"/tmp:size=(\d+)m#', $web, $m));
        self::assertGreaterThanOrEqual(128, (int) $m[1]);
        self::assertStringContainsString('client_body_temp_path /tmp/client_temp', self::file('docker/nginx/default.conf'));
    }

    /**
     * Errors nginx answers itself carry the same problem+json body and security headers as the
     * application's, and are never cached.
     */
    #[Group('EXG-SEC-058')]
    #[Group('EXG-CACHE-008')]
    public function testNginxOwnErrorsAreUniformAndHardened(): void
    {
        $nginx = self::file('docker/nginx/default.conf');
        foreach ([400, 404, 405, 408, 413, 414] as $status) {
            self::assertStringContainsString("error_page {$status} /__errors/{$status}.json;", $nginx);
            $body = json_decode(self::file("docker/nginx/errors/{$status}.json"), true);
            self::assertIsArray($body);
            self::assertSame($status, $body['status']);
            self::assertSame('about:blank', $body['type']);
        }
        self::assertMatchesRegularExpression('/location \^~ \/__errors\/ \{\s*internal;.*?problem\+json;.*?Content-Security-Policy.*?Cache-Control "no-store, private"/s', $nginx);
        self::assertStringContainsString('COPY docker/nginx/errors /usr/share/quietlink/__errors', self::file('docker/nginx/Dockerfile'));
    }

    #[Group('EXG-DEPLOY-001')]
    public function testPhpDisablesProcessExecution(): void
    {
        $ini = self::file('docker/php/php.ini');
        self::assertMatchesRegularExpression('/^disable_functions\s*=\s*exec,\s*passthru,\s*shell_exec,\s*system,\s*popen,\s*pcntl_exec\s*$/m', $ini);
        // DiskProbe runs df through proc_open (free inodes, §9.4): it stays available.
        self::assertDoesNotMatchRegularExpression('/^disable_functions\s*=.*\bproc_open\b/m', $ini);
    }

    #[Group('EXG-DEPLOY-001')]
    public function testComposeHasNoDatabaseAndRunsHardened(): void
    {
        $compose = self::file('compose.yaml');

        self::assertDoesNotMatchRegularExpression('/^\s*image:.*(postgres|mysql|mariadb|redis|valkey|mongo)/im', $compose);
        self::assertStringContainsString('read_only: true', $compose);
        self::assertStringContainsString('cap_drop: [ALL]', $compose);
        self::assertStringContainsString('no-new-privileges:true', $compose);
        self::assertStringContainsString('app:purge-expired', $compose);
        self::assertStringContainsString('USER 10001:10001', self::file('docker/php/Dockerfile'));
    }

    /**
     * The default data directory (storage.data_dir = datas) exists in a fresh clone, while the
     * stored content it receives is never committed.
     */
    #[Group('EXG-STORE-041')]
    public function testDefaultDataDirectoryIsVersionedButItsContentIsIgnored(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/datas/.gitkeep');
        $gitignore = self::file('.gitignore');
        self::assertMatchesRegularExpression('#^/datas/\*$#m', $gitignore);
        self::assertMatchesRegularExpression('#^!/datas/\.gitkeep$#m', $gitignore);
    }

    /**
     * The app container is healthy only when PHP-FPM listens and app:boot has run for the
     * current configuration (app:config:check exits 0), not merely when the port is open.
     */
    #[Group('EXG-OPS-007')]
    public function testAppHealthcheckRequiresAMatchingBootMarker(): void
    {
        $compose = self::file('compose.yaml');
        $app = substr($compose, (int) strpos($compose, "  app:\n"), 600);

        self::assertStringContainsString('app:config:check --format=json', $app);
        self::assertStringContainsString("fsockopen('127.0.0.1', 9000)", $app);
    }

    /**
     * Local state, test reports and build outputs never enter the Docker build context.
     */
    #[Group('EXG-OPS-007')]
    public function testBuildContextExcludesDataReportsAndBuildOutputs(): void
    {
        $ignore = array_map('trim', explode("\n", self::file('.dockerignore')));
        foreach (['datas', 'test-results', 'playwright-report', 'quietlink.phar', 'coverage'] as $entry) {
            self::assertContains($entry, $ignore);
        }
    }

    /**
     * The purge loop traps TERM to stop between runs; the image's SIGQUIT stop signal (for
     * PHP-FPM) would skip the trap and kill it.
     */
    #[Group('EXG-OPS-007')]
    public function testPurgeServiceStopsOnTerm(): void
    {
        $compose = self::file('compose.yaml');
        $purge = substr($compose, (int) strpos($compose, "  purge:\n"), 500);
        self::assertStringContainsString('stop_signal: SIGTERM', $purge);
        self::assertContains('.claude', array_map('trim', explode("\n", self::file('.dockerignore'))));
    }
}
