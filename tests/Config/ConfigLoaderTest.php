<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Config\ConfigLoader;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\InvalidConfigException;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(InstanceConfig::class)]
final class ConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quietlink-config-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/themes', 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/{,themes/}*', GLOB_BRACE);
        foreach ($files === false ? [] : $files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir . '/themes');
        rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config, string $file = 'config.php'): void
    {
        file_put_contents($this->dir . '/' . $file, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * @return array<string, string>
     */
    private static function env(): array
    {
        return ['QUIETLINK_APP_SECRET' => base64_encode(str_repeat("\x11", 32))];
    }

    /**
     * @param array<string, string>|null $env
     */
    private function load(?array $env = null): InstanceConfig
    {
        return ConfigLoader::load($this->dir, $env ?? self::env());
    }

    /**
     * @param array<string, mixed>       $config
     * @param array<string, string>|null $env
     */
    private function assertInvalid(array $config, string $expectedFragment, ?array $env = null): void
    {
        $this->writeConfig($config);
        try {
            $this->load($env);
            self::fail('Configuration should be rejected.');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString($expectedFragment, implode("\n", $e->errors));
        }
    }

    private const MINIMAL = ['app' => ['public_url' => 'https://paste.example.test']];

    #[Group('EXG-CONF-002')]
    #[Group('EXG-CONF-003')]
    #[Group('EXG-CONF-018')]
    #[Group('EXG-CONF-020')]
    #[Group('EXG-CONF-005')]
    #[Group('EXG-CONF-012')]
    #[Group('EXG-CONF-019')]
    #[Group('EXG-GEN-002')]
    public function testMinimalConfigurationUsesSpecDefaults(): void
    {
        $this->writeConfig(self::MINIMAL);
        $config = $this->load();

        self::assertSame('https://paste.example.test', $config->app->publicUrl);
        self::assertSame(['en', 'fr'], $config->app->enabledLocales);
        self::assertSame('1d', $config->paste->defaultExpiration);
        self::assertSame(['5m', '1h', '1d', '7d', '30d'], $config->paste->allowedExpirations);
        self::assertFalse($config->paste->allowForever);
        self::assertTrue($config->paste->allowReadOnce);
        self::assertTrue($config->paste->allowPassphrase);
        self::assertSame(1048576, $config->paste->maxEnvelopeBytes);
        self::assertSame(1048592, $config->paste->maxCiphertextBytes);
        self::assertSame(4096, $config->paste->maxMetadataBytes);
        self::assertSame(30 * 86400, $config->paste->maxRetentionSeconds);
        self::assertSame(3, $config->paste->maxUnconfirmedOpens);
        self::assertSame(60, $config->paste->readOnceReservationTtl);
        self::assertSame(86400, $config->paste->idempotencyMaxTtlSeconds);
        self::assertSame(10737418240, $config->storage->maxTotalBytes);
        self::assertSame(100000, $config->storage->maxItems);
        self::assertSame(1441792, $config->http->maxRequestBytes);
        self::assertTrue($config->ui->enableQrCode);
        self::assertFalse($config->ui->allowPrint);
        self::assertSame(30, $config->http->rateLimits['create']->limit);
        self::assertSame(600, $config->http->rateLimits['create']->intervalSeconds);
        self::assertSame(32, strlen($config->secret->bytes()));
    }

    #[Group('EXG-CONF-030')]
    public function testLocalFileOverridesMainFile(): void
    {
        $this->writeConfig(self::MINIMAL);
        $this->writeConfig(['paste' => ['default_expiration' => '1h']], 'config.local.php');

        self::assertSame('1h', $this->load()->paste->defaultExpiration);
    }

    public function testMissingConfigurationFileIsRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->load();
    }

    #[Group('EXG-CONF-021')]
    public function testFailingConfigurationFileIsAValidationError(): void
    {
        file_put_contents($this->dir . '/config.php', "<?php\n\nthrow new \\RuntimeException('dummy-file-content');\n");
        try {
            $this->load();
            self::fail('Configuration should be rejected.');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('config.php could not be loaded (RuntimeException)', $e->getMessage());
            self::assertStringNotContainsString('dummy-file-content', $e->getMessage());
        }
    }

    #[Group('EXG-CONF-021')]
    #[Group('EXG-DEPLOY-023')]
    public function testUnknownKeyIsRejected(): void
    {
        $this->assertInvalid(self::MINIMAL + ['secret' => 'nope'], 'secret');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        $base = self::MINIMAL;
        yield 'never in allowed expirations' => [$base + ['paste' => ['allowed_expirations' => ['1d', 'never']]], 'allowed_expirations'];
        yield 'forever with retention' => [$base + ['paste' => ['allow_forever' => true]], 'allow_forever'];
        yield 'default not allowed' => [$base + ['paste' => ['default_expiration' => '30d', 'allowed_expirations' => ['1d']]], 'default_expiration'];
        yield 'expiration above retention' => [$base + ['paste' => ['max_retention' => '7d']], 'max_retention'];
        yield 'bad duration format' => [$base + ['paste' => ['max_retention' => '7 days']], 'max_retention'];
        yield 'unconfirmed opens too high' => [$base + ['paste' => ['max_unconfirmed_opens' => 11]], 'max_unconfirmed_opens'];
        yield 'unconfirmed opens zero' => [$base + ['paste' => ['max_unconfirmed_opens' => 0]], 'max_unconfirmed_opens'];
        yield 'reservation too short' => [$base + ['paste' => ['read_once_reservation_ttl' => 29]], 'read_once_reservation_ttl'];
        yield 'reservation too long' => [$base + ['paste' => ['read_once_reservation_ttl' => 301]], 'read_once_reservation_ttl'];
        yield 'idempotency too short' => [$base + ['paste' => ['idempotency_max_ttl' => '59m']], 'idempotency_max_ttl'];
        yield 'idempotency too long' => [$base + ['paste' => ['idempotency_max_ttl' => '8d']], 'idempotency_max_ttl'];
        yield 'request body too small' => [$base + ['http' => ['max_request_bytes' => 1000000]], 'max_request_bytes'];
        yield 'wrong type' => [$base + ['paste' => ['allow_read_once' => 'yes']], 'allow_read_once'];
        yield 'english disabled' => [['app' => ['public_url' => 'https://paste.example.test', 'enabled_locales' => ['fr']]], 'enabled_locales'];
        yield 'unknown locale' => [['app' => ['public_url' => 'https://paste.example.test', 'enabled_locales' => ['en', 'xx']]], 'enabled_locales'];
        yield 'plain http public url' => [['app' => ['public_url' => 'http://paste.example.test']], 'public_url'];
        yield 'public url with path' => [['app' => ['public_url' => 'https://paste.example.test/sub?x=1']], 'public_url'];
        yield 'absolute tokens file' => [$base + ['theme' => ['custom_tokens_file' => '/etc/passwd']], 'custom_tokens_file'];
        yield 'traversing tokens file' => [$base + ['theme' => ['custom_tokens_file' => '../x.json']], 'custom_tokens_file'];
        yield 'relative storage path' => [$base + ['storage' => ['root_dir' => 'pastes']], 'root_dir'];
        yield 'ipv6 prefix out of range' => [$base + ['http' => ['ratelimit_ipv6_prefix' => 32]], 'ratelimit_ipv6_prefix'];
        yield 'rate limit zero' => [$base + ['http' => ['rate_limits' => ['create' => ['limit' => 0, 'interval' => 60]]]], 'rate_limits'];
        yield 'unknown rate limit bucket' => [$base + ['http' => ['rate_limits' => ['upload' => ['limit' => 1, 'interval' => 60]]]], 'rate_limits'];
        // Non-string list items must be validation errors, never an internal failure.
        yield 'integer trusted proxy' => [$base + ['http' => ['trusted_proxies' => [1]]], 'http.trusted_proxies'];
        yield 'nested cors origin' => [$base + ['http' => ['cors_allowed_origins' => [['x']]]], 'http.cors_allowed_origins'];
        yield 'null locale' => [['app' => ['public_url' => 'https://paste.example.test', 'enabled_locales' => [null]]], 'app.enabled_locales'];
        yield 'integer expiration' => [$base + ['paste' => ['allowed_expirations' => [5]]], 'paste.allowed_expirations'];
        yield 'boolean template' => [$base + ['ui' => ['templates' => [true]]], 'ui.templates'];
        yield 'envelope limit overflow' => [$base + ['paste' => ['max_envelope_bytes' => PHP_INT_MAX]], 'max_envelope_bytes'];
        yield 'envelope limit above ceiling' => [$base + ['paste' => ['max_envelope_bytes' => 16777217], 'http' => ['max_request_bytes' => PHP_INT_MAX]], 'max_envelope_bytes'];
    }

    #[Group('EXG-CONF-002')]
    #[Group('EXG-CONF-004')]
    public function testEnvelopeLimitCeilingIsAccepted(): void
    {
        $this->writeConfig(self::MINIMAL + ['paste' => ['max_envelope_bytes' => 16777216], 'http' => ['max_request_bytes' => 22396929]]);

        self::assertSame(16777232, $this->load()->paste->maxCiphertextBytes);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    #[Group('EXG-CONF-001')]
    #[Group('EXG-CONF-004')]
    #[Group('EXG-CONF-013')]
    #[Group('EXG-CONF-014')]
    #[Group('EXG-CONF-024')]
    #[Group('EXG-CONF-025')]
    #[Group('EXG-CONF-026')]
    #[Group('EXG-CONF-027')]
    #[Group('EXG-LIFE-006')]
    #[Group('EXG-LIFE-018')]
    #[Group('EXG-SEC-102')]
    #[Group('EXG-TEST-025')]
    public function testInconsistentConfigurationIsRejected(array $config, string $field): void
    {
        $this->assertInvalid($config, $field);
    }

    public function testForeverRequiresNullRetention(): void
    {
        $this->writeConfig(self::MINIMAL + ['paste' => ['allow_forever' => true, 'max_retention' => null]]);
        $config = $this->load();

        self::assertTrue($config->paste->allowForever);
        self::assertNull($config->paste->maxRetentionSeconds);
        self::assertSame(['5m', '1h', '1d', '7d', '30d', 'never'], $config->paste->acceptedExpirationCodes());
    }

    public function testHttpLocalhostIsAllowedForDevelopment(): void
    {
        $this->writeConfig(['app' => ['public_url' => 'http://localhost:8080']]);

        self::assertSame('http://localhost:8080', $this->load()->app->publicUrl);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'missing' => [[]];
        yield 'not base64' => [['QUIETLINK_APP_SECRET' => '***not base64***']];
        yield 'too short' => [['QUIETLINK_APP_SECRET' => base64_encode(str_repeat("\x01", 31))]];
        yield 'both sources' => [['QUIETLINK_APP_SECRET' => base64_encode(str_repeat("\x01", 32)), 'QUIETLINK_APP_SECRET_FILE' => '/nonexistent']];
        yield 'missing file' => [['QUIETLINK_APP_SECRET_FILE' => '/nonexistent/secret']];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('invalidSecrets')]
    #[Group('EXG-CONF-028')]
    public function testInvalidSecretIsRejectedWithoutBeingDisclosed(array $env): void
    {
        $this->assertInvalid(self::MINIMAL, 'QUIETLINK_APP_SECRET', $env);
    }

    #[Group('EXG-CONF-015')]
    #[Group('EXG-TEST-042')]
    #[Group('EXG-DEPLOY-023')]
    public function testSecretCanBeReadFromFile(): void
    {
        $this->writeConfig(self::MINIMAL);
        file_put_contents($this->dir . '/secret', base64_encode(str_repeat("\x22", 32)) . "\n");

        $config = $this->load(['QUIETLINK_APP_SECRET_FILE' => $this->dir . '/secret']);

        self::assertSame(str_repeat("\x22", 32), $config->secret->bytes());
    }

    public function testFingerprintIsStableAndTracksChanges(): void
    {
        $this->writeConfig(self::MINIMAL);
        $first = $this->load()->fingerprint();
        self::assertSame($first, $this->load()->fingerprint());

        $this->writeConfig(self::MINIMAL + ['paste' => ['max_unconfirmed_opens' => 4]]);
        self::assertNotSame($first, $this->load()->fingerprint());
    }

    public function testSecretCheckDoesNotRevealTheSecret(): void
    {
        $this->writeConfig(self::MINIMAL);
        $config = $this->load();

        self::assertSame(64, strlen($config->secret->check()));
        self::assertStringNotContainsString(bin2hex($config->secret->bytes()), $config->secret->check());
        self::assertStringNotContainsString(base64_encode($config->secret->bytes()), var_export($config->describe(), true));
    }
}
