<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use QuietLink\Clock\SystemClock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Kernel;
use QuietLink\Maintenance\Booter;
use QuietLink\Maintenance\DiskProbe;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the real kernel against a temporary instance (config + storage + boot marker).
 */
abstract class KernelTestCase extends TestCase
{
    protected TempDirectory $tmp;
    protected InstanceConfig $config;
    private ?Kernel $kernel = null;

    /**
     * @param array<string, array<string, mixed>> $overrides
     * @param array<string, string>               $configFiles extra files written below config/ first
     */
    protected function bootInstance(array $overrides = [], bool $writeBootMarker = true, array $configFiles = []): void
    {
        $this->tmp = new TempDirectory();
        foreach ($configFiles as $name => $content) {
            @mkdir(dirname($this->tmp->path . '/config/' . $name), 0700, true);
            file_put_contents($this->tmp->path . '/config/' . $name, $content);
        }
        $this->config = TestInstance::config($this->tmp, $overrides);
        $_SERVER['QUIETLINK_CONFIG_DIR'] = $this->tmp->path . '/config';
        $_SERVER['QUIETLINK_APP_SECRET'] = TestInstance::SECRET_BASE64;
        if ($writeBootMarker) {
            $booter = new Booter($this->tmp->path . '/public', new class () extends DiskProbe {
                public function freeBytes(string $path): int
                {
                    return 1 << 40;
                }

                public function freeInodesPercent(string $path): int
                {
                    return 90;
                }

                public function filesystemType(string $path): string
                {
                    // Deterministic across hosts (CI containers report overlay or tmpfs).
                    return 'ext4';
                }
            }, new SystemClock());
            self::assertSame([], $booter->boot($this->config, null));
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        unset($_SERVER['QUIETLINK_CONFIG_DIR'], $_SERVER['QUIETLINK_APP_SECRET']);
        if (isset($this->tmp)) {
            $this->tmp->remove();
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server  overrides of the server variables
     */
    protected function request(string $method, string $uri, ?string $body = null, array $headers = [], array $server = []): Response
    {
        $server += ['REMOTE_ADDR' => '192.0.2.10', 'HTTPS' => 'on', 'HTTP_HOST' => 'paste.example.test'];
        $server = array_filter($server, static fn (string $value): bool => $value !== '');
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        if ($body !== null && !isset($headers['Content-Type'])) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->kernel?->shutdown();
        $this->kernel = new Kernel('test', true);
        $request = Request::create($uri, $method, [], [], [], $server, $body);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        self::assertIsString($value, $key);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
