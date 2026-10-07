<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Runtime;

use QuietLink\Config\ConfigLoader;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageLayout;
use Throwable;

/**
 * Loads the instance configuration at runtime (never compiled into the container, §9.5)
 * and checks it against the boot marker written by app:boot.
 */
final class RuntimeStatus
{
    private ?InstanceConfig $config = null;
    private ?bool $ready = null;
    private ?InvalidConfigException $failure = null;

    public function __construct(private readonly string $configDir)
    {
    }

    /**
     * Any failure while loading is reported as InvalidConfigException, so that every caller
     * falls back to the generic 503 with security headers (§9.5).
     *
     * @throws InvalidConfigException
     */
    public function config(): InstanceConfig
    {
        if ($this->config !== null) {
            return $this->config;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        try {
            return $this->config = ConfigLoader::load($this->configDir, Environment::processVariables());
        } catch (InvalidConfigException $e) {
            throw $this->failure = $e;
        } catch (Throwable $e) {
            throw $this->failure = new InvalidConfigException([sprintf('Configuration could not be loaded (%s).', $e::class)]);
        }
    }

    /**
     * Why the instance is not ready, or null when it is: config_invalid, marker_missing (no or
     * unreadable boot.json), fingerprint_differs (configuration or resolved paths changed since
     * app:boot, or seen through another path), secret_differs. Never contains a value.
     */
    public function notReadyReason(): ?string
    {
        try {
            $config = $this->config();
        } catch (InvalidConfigException) {
            return 'config_invalid';
        }
        $boot = (new StateFiles(self::layout($config)))->boot();
        if ($boot === null) {
            return 'marker_missing';
        }
        try {
            if (!hash_equals($boot['config_fingerprint'], $config->fingerprint())) {
                return 'fingerprint_differs';
            }
        } catch (\JsonException) {
            return 'config_invalid';
        }

        return hash_equals($boot['secret_check'], $config->secret->check()) ? null : 'secret_differs';
    }

    /** Directory relative storage paths are resolved from (it differs if reached by an alias). */
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * True when the configuration is valid and matches boot.json (fingerprint and secret check).
     */
    public function isReady(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }
        try {
            $config = $this->config();
        } catch (InvalidConfigException) {
            return $this->ready = false;
        }
        $files = new StateFiles(self::layout($config));
        try {
            return $this->ready = $files->bootMatches($config->fingerprint(), $config->secret->check());
        } catch (\JsonException) {
            // A value that cannot be fingerprinted: not ready (503), never an internal error.
            return $this->ready = false;
        }
    }

    public static function layout(InstanceConfig $config): StorageLayout
    {
        return new StorageLayout($config->storage->rootDir, $config->storage->idempotencyDir, $config->storage->stateDir);
    }
}
