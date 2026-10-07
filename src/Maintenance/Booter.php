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
 * A dry run performs the same checks but creates, writes and deletes nothing: missing
 * directories become warnings, and the atomic rename()/link() probe, which needs test files,
 * is skipped.
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
        private readonly string $configDir = '',
        private readonly ?string $postMaxSize = null,
        private readonly ?string $dotEnvFile = null,
    ) {
    }

    /**
     * @param string|null $secretFile value of QUIETLINK_APP_SECRET_FILE, checked for its mode only
     *
     * @return list<string> errors (empty on success)
     */
    public function boot(InstanceConfig $config, ?string $fpmPoolFile, bool $dryRun = false, ?string $secretFile = null): array
    {
        $this->warnings = [];
        $errors = [...self::checkRuntime(), ...$this->checkDirectories($config, $dryRun)];
        if ($errors !== []) {
            return $errors;
        }

        $layout = RuntimeStatus::layout($config);
        if (!$dryRun) {
            try {
                $layout->ensureDirectories();
                (new RateLimiter($config->storage->ratelimitDir, $config->http->rateLimits, $config->secret, $this->clock))->ensureLockFiles();
                if (!is_file($layout->stateDir . '/purge.lock')) {
                    AtomicFile::createExclusive($layout->stateDir . '/purge.lock', '', false);
                }
            } catch (StorageException) {
                return ['Unable to prepare the storage lock files.'];
            }
        }

        if ($fpmPoolFile === null) {
            $this->warnings[] = 'QUIETLINK_FPM_POOL_FILE is not set: the PHP-FPM pool was not checked for the secret variable.';
        } else {
            // The workers start with a cleared environment: the pool must pass the variable this
            // instance uses, or boot succeeds while every request answers 503.
            $pool = @file_get_contents($fpmPoolFile);
            [$variable, $mode] = $secretFile !== null ? ['QUIETLINK_APP_SECRET_FILE', 'a secret file'] : ['QUIETLINK_APP_SECRET', 'an inline secret'];
            if ($pool === false || preg_match('/^\s*env\[' . $variable . '\]\s*=/m', $pool) !== 1) {
                return [sprintf('The PHP-FPM pool does not pass %s to the workers (this instance uses %s).', $variable, $mode)];
            }
        }

        foreach ($dryRun ? [] : $this->themeBuilders as $builder) {
            try {
                $builder->build($config);
            } catch (Throwable $e) {
                return ['Theme generation failed: ' . $e->getMessage()];
            }
        }

        $now = $this->clock->now();
        $measured = self::existingAncestor($config->storage->rootDir);
        $freeBytes = $this->disk->freeBytes($measured);
        $inodes = $this->disk->freeInodesPercent($measured);
        if (!$dryRun && !$this->writeState(static fn () => (new StateFiles($layout))->writeHealth($now, $freeBytes, $inodes))) {
            return ['Unable to write the state files (health.json, boot.json).'];
        }
        if ($freeBytes < $config->storage->minFreeBytes) {
            $this->warnings[] = 'Free disk space is below storage.min_free_bytes: creation is refused.';
        }
        if ($inodes === null) {
            $this->warnings[] = 'Free inodes could not be measured (df unavailable or not reported by the filesystem): storage.min_free_inodes_percent is not enforced.';
        } elseif ($inodes < $config->storage->minFreeInodesPercent) {
            $this->warnings[] = 'Free inodes are below storage.min_free_inodes_percent: creation is refused.';
        }
        $secretMode = $secretFile !== null && is_file($secretFile) ? @fileperms($secretFile) : false;
        $dotEnvMode = $this->dotEnvFile !== null && is_file($this->dotEnvFile) ? @fileperms($this->dotEnvFile) : false;
        if ($dotEnvMode !== false && ($dotEnvMode & 0004) !== 0) {
            $this->warnings[] = '.env is readable by every account: it may hold the secret, restrict it (chmod 600).';
        }
        if ($secretMode !== false && ($secretMode & 0004) !== 0) {
            $this->warnings[] = 'The secret file (QUIETLINK_APP_SECRET_FILE) is readable by every account: restrict it to the account or group running PHP (chmod 640 or 600).';
        }
        foreach ([$this->configDir . '/config.php', $this->configDir . '/config.local.php'] as $file) {
            $mode = is_file($file) ? @fileperms($file) : false;
            if ($mode !== false && ($mode & 0004) !== 0) {
                $this->warnings[] = sprintf('%s is readable by every account: restrict it to the account or group running PHP (for example chgrp to that group and chmod 640).', basename($file));
            }
        }
        $postMax = $this->postMaxSize ?? (string) ini_get('post_max_size');
        if (self::iniBytes($postMax) < $config->http->maxRequestBytes) {
            $this->warnings[] = sprintf('PHP post_max_size (%s) is below http.max_request_bytes (%d): large creations will fail; raise it (and the web server body limit) together.', $postMax, $config->http->maxRequestBytes);
        }
        if ($config->http->trustedProxies === [] && str_starts_with($config->app->publicUrl, 'https://')) {
            $this->warnings[] = 'http.trusted_proxies is empty: behind a reverse proxy all clients share one rate limiting key.';
        }

        if (!$dryRun && !$this->writeState(static fn () => (new StateFiles($layout))->writeBoot($now, $config->fingerprint(), $config->secret->check()))) {
            return ['Unable to write the state files (health.json, boot.json).'];
        }

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
    private function checkDirectories(InstanceConfig $config, bool $dryRun): array
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
            if ($dryRun && !is_dir($dir)) {
                // Checked through the closest existing parent, which app:boot would create it in.
                $parent = self::existingAncestor($dir);
                if (file_exists($dir) || !is_dir($parent) || !is_writable($parent)) {
                    $errors[] = sprintf('%s cannot be created.', $name);
                    continue;
                }
                $this->warnings[] = sprintf('%s does not exist yet: app:boot will create it.', $name);
                $error = $this->checkFilesystem($name, $parent, $storage->allowUnsupportedFs);
                if ($error !== null) {
                    $errors[] = $error;
                }
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
            // Ciphertexts, metadata, rate limiting and idempotency records stay private (§9.5).
            $actual = @fileperms($real);
            if ($mode === 0700 && $actual !== false && ($actual & 0077) !== 0) {
                $errors[] = sprintf('%s is accessible to other accounts (mode %04o): restore mode 0700 (chmod 700).', $name, $actual & 0777);
                continue;
            }
            if (!$dryRun && !self::supportsAtomicOperations($real)) {
                $errors[] = sprintf('%s does not support atomic rename() and link().', $name);
            }
            $error = $this->checkFilesystem($name, $real, $storage->allowUnsupportedFs);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** Error for an unsupported filesystem; warnings when it is allowed or cannot be determined. */
    private function checkFilesystem(string $name, string $path, bool $allowUnsupported): ?string
    {
        $type = $this->disk->filesystemType($path);
        // §9.4.1: an undetermined type is refused like an unsupported one.
        if ($type === null) {
            if (!$allowUnsupported) {
                return sprintf('The filesystem type of %s could not be determined (ext4 or XFS required); set storage.allow_unsupported_fs = true to accept it, at the cost of locking guarantees.', $name);
            }
            $this->warnings[] = sprintf('The filesystem type of %s could not be determined, allowed by storage.allow_unsupported_fs.', $name);
        } elseif (!in_array($type, DiskProbe::SUPPORTED_FILESYSTEMS, true)) {
            if (!$allowUnsupported) {
                return sprintf('%s is on an unsupported filesystem (%s); set storage.allow_unsupported_fs = true to accept it, at the cost of locking guarantees.', $name, $type);
            }
            $this->warnings[] = sprintf('%s is on an unsupported filesystem (%s), allowed by storage.allow_unsupported_fs.', $name, $type);
        }

        return null;
    }

    /**
     * @param callable(): void $write
     */
    private function writeState(callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (StorageException) {
            return false;
        }
    }

    /**
     * Bytes of a php.ini size as PHP reads it: leading digits (0x, 0o and 0b prefixes as in
     * PHP 8.2+), the last character as unit
     * (k, m, g); "0", no digits or an overflow means no limit (PHP_INT_MAX).
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(?:0x([0-9a-f]+)|0o([0-7]+)|0b([01]+)|(\d+))/i', $value, $m) !== 1) {
            return PHP_INT_MAX;
        }
        [$hex, $octal, $binary, $decimal] = array_pad(array_slice($m, 1), 4, '');
        $number = match (true) {
            $hex !== '' => (float) hexdec($hex),
            $octal !== '' => (float) octdec($octal),
            $binary !== '' => (float) bindec($binary),
            default => (float) $decimal,
        };
        $bytes = $number * match (strtolower(substr($value, -1))) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };

        return $bytes === 0.0 || $bytes >= PHP_INT_MAX ? PHP_INT_MAX : (int) $bytes;
    }

    /** The path itself or its closest existing parent, for disk measurements before creation. */
    private static function existingAncestor(string $path): string
    {
        while (!file_exists($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return $path;
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
