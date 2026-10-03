<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Runtime;

use QuietLink\Config\ConfigLoader;
use QuietLink\Config\InstanceConfig;
use QuietLink\Config\InvalidConfigException;
use QuietLink\Storage\StateFiles;
use QuietLink\Storage\StorageLayout;

/**
 * Loads the instance configuration at runtime (never compiled into the container, §9.5)
 * and checks it against the boot marker written by app:boot.
 */
final class RuntimeStatus
{
    private ?InstanceConfig $config = null;
    private ?bool $ready = null;

    public function __construct(private readonly string $configDir)
    {
    }

    /**
     * @throws InvalidConfigException
     */
    public function config(): InstanceConfig
    {
        return $this->config ??= ConfigLoader::load($this->configDir, Environment::processVariables());
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

        return $this->ready = $files->bootMatches($config->fingerprint(), $config->secret->check());
    }

    public static function layout(InstanceConfig $config): StorageLayout
    {
        return new StorageLayout($config->storage->rootDir, $config->storage->idempotencyDir, $config->storage->stateDir);
    }
}
