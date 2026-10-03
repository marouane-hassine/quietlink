<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Maintenance;

use QuietLink\Clock\Clock;
use QuietLink\Config\InstanceConfig;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Storage\AtomicFile;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageException;
use Throwable;

/**
 * Checks performed by `app:boot` before PHP-FPM starts (§9.5). Writes boot.json on success.
 */
final class Booter
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param list<ThemeBuilder> $themeBuilders generate the theme token CSS
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly DiskProbe $disk,
        private readonly Clock $clock,
        private readonly array $themeBuilders = [],
    ) {
    }

    /**
     * @return list<string> errors (empty on success)
     */
    public function boot(InstanceConfig $config, ?string $fpmPoolFile): array
    {
        $this->warnings = [];
        $errors = [...self::checkRuntime(), ...$this->checkDirectories($config)];
        if ($errors !== []) {
            return $errors;
        }

        $layout = RuntimeStatus::layout($config);
        try {
            $layout->ensureDirectories();
            (new RateLimiter($config->storage->ratelimitDir, $config->http->rateLimits, $config->secret, $this->clock))->ensureLockFiles();
            if (!is_file($layout->stateDir . '/purge.lock')) {
                AtomicFile::createExclusive($layout->stateDir . '/purge.lock', '', false);
            }
        } catch (StorageException) {
            return ['Unable to prepare the storage lock files.'];
        }

        if ($fpmPoolFile === null) {
            $this->warnings[] = 'QUIETLINK_FPM_POOL_FILE is not set: the PHP-FPM pool was not checked for the secret variable.';
        } else {
            $pool = @file_get_contents($fpmPoolFile);
            if ($pool === false || preg_match('/^\s*env\[QUIETLINK_APP_SECRET(_FILE)?\]\s*=/m', $pool) !== 1) {
                return ['The PHP-FPM pool does not pass QUIETLINK_APP_SECRET or QUIETLINK_APP_SECRET_FILE to the workers.'];
            }
        }

        foreach ($this->themeBuilders as $builder) {
            try {
                $builder->build($config);
            } catch (Throwable $e) {
                return ['Theme generation failed: ' . $e->getMessage()];
            }
        }

        $now = $this->clock->now();
        $freeBytes = $this->disk->freeBytes($config->storage->rootDir);
        $inodes = $this->disk->freeInodesPercent($config->storage->rootDir);
        (new StateFiles($layout))->writeHealth($now, $freeBytes, $inodes);
        if ($freeBytes < $config->storage->minFreeBytes) {
            $this->warnings[] = 'Free disk space is below storage.min_free_bytes: creation is refused.';
        }
        if ($inodes !== null && $inodes < $config->storage->minFreeInodesPercent) {
            $this->warnings[] = 'Free inodes are below storage.min_free_inodes_percent: creation is refused.';
        }
        if ($config->http->trustedProxies === [] && str_starts_with($config->app->publicUrl, 'https://')) {
            $this->warnings[] = 'http.trusted_proxies is empty: behind a reverse proxy all clients share one rate limiting key.';
        }

        (new StateFiles($layout))->writeBoot($now, $config->fingerprint(), $config->secret->check());

        return [];
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Required extensions and functions (§9.2); no primitive is ever reimplemented.
     *
     * @return list<string>
     */
    public static function checkRuntime(): array
    {
        $errors = [];
        foreach (['openssl', 'sodium', 'intl', 'hash', 'json', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = sprintf('The PHP extension "%s" is required.', $extension);
            }
        }
        foreach (['sodium_crypto_sign_seed_keypair', 'sodium_crypto_sign_verify_detached', 'sodium_crypto_pwhash', 'hash_hkdf', 'random_bytes', 'openssl_encrypt'] as $function) {
            if (!function_exists($function)) {
                $errors[] = sprintf('The PHP function "%s" is required.', $function);
            }
        }
        if (function_exists('openssl_get_cipher_methods') && !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $errors[] = 'OpenSSL does not provide aes-256-gcm.';
        }
        if (!defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
            $errors[] = 'libsodium does not provide Argon2id.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function checkDirectories(InstanceConfig $config): array
    {
        $errors = [];
        $public = realpath($this->publicDir);
        $storage = $config->storage;
        $dirs = [
            'storage.root_dir' => [$storage->rootDir, 0700],
            'storage.idempotency_dir' => [$storage->idempotencyDir, 0700],
            'storage.ratelimit_dir' => [$storage->ratelimitDir, 0700],
            'storage.state_dir' => [$storage->stateDir, 0700],
            'storage.generated_assets_dir' => [$storage->generatedAssetsDir, 0755],
        ];
        foreach ($dirs as $name => [$dir, $mode]) {
            if (is_link($dir)) {
                $errors[] = sprintf('%s must not be a symbolic link.', $name);
                continue;
            }
            if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
                $errors[] = sprintf('%s cannot be created.', $name);
                continue;
            }
            $real = realpath($dir);
            if ($real === false || ($public !== false && ($real === $public || str_starts_with($real . '/', $public . '/')))) {
                $errors[] = sprintf('%s must be outside the web root.', $name);
                continue;
            }
            if (function_exists('posix_geteuid') && @fileowner($real) !== posix_geteuid()) {
                $errors[] = sprintf('%s must belong to the application account.', $name);
                continue;
            }
            if (!self::supportsAtomicOperations($real)) {
                $errors[] = sprintf('%s does not support atomic rename() and link().', $name);
            }
            $type = $this->disk->filesystemType($real);
            if ($type !== null && !in_array($type, DiskProbe::SUPPORTED_FILESYSTEMS, true)) {
                if ($storage->allowUnsupportedFs) {
                    $this->warnings[] = sprintf('%s is on an unsupported filesystem (%s), allowed by storage.allow_unsupported_fs.', $name, $type);
                } else {
                    $errors[] = sprintf('%s is on an unsupported filesystem (%s); set storage.allow_unsupported_fs only for development.', $name, $type);
                }
            } elseif ($type === null) {
                $this->warnings[] = sprintf('The filesystem type of %s could not be determined.', $name);
            }
        }

        return $errors;
    }

    private static function supportsAtomicOperations(string $dir): bool
    {
        $base = $dir . '/.boot-check-' . bin2hex(random_bytes(6));
        try {
            AtomicFile::createExclusive($base . '.a', 'check', true);
            $ok = @rename($base . '.a', $base . '.b') && @link($base . '.b', $base . '.c');
        } catch (StorageException) {
            $ok = false;
        }
        foreach (['.a', '.b', '.c'] as $suffix) {
            @unlink($base . $suffix);
        }

        return $ok;
    }
}
