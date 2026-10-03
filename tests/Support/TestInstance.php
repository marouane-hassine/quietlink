<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Support;

use QuietLink\Config\ConfigLoader;
use QuietLink\Config\InstanceConfig;
use QuietLink\Storage\StorageLayout;

/**
 * Builds a valid instance configuration whose storage lives in a temporary directory.
 */
final class TestInstance
{
    public const SECRET_BASE64 = 'ERERERERERERERERERERERERERERERERERERERERERE=';

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    public static function config(TempDirectory $tmp, array $overrides = []): InstanceConfig
    {
        $configDir = $tmp->path . '/config';
        @mkdir($configDir . '/themes', 0700, true);
        $config = array_replace_recursive([
            'app' => ['public_url' => 'https://paste.example.test'],
            'log' => ['level' => 'warning'],
            'storage' => [
                'root_dir' => $tmp->path . '/data/pastes',
                'idempotency_dir' => $tmp->path . '/data/idempotency',
                'ratelimit_dir' => $tmp->path . '/data/ratelimit',
                'state_dir' => $tmp->path . '/data/state',
                'generated_assets_dir' => $tmp->path . '/generated',
            ],
        ], $overrides);
        file_put_contents($configDir . '/config.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

        return ConfigLoader::load($configDir, ['QUIETLINK_APP_SECRET' => self::SECRET_BASE64]);
    }

    public static function layout(InstanceConfig $config): StorageLayout
    {
        $layout = new StorageLayout($config->storage->rootDir, $config->storage->idempotencyDir, $config->storage->stateDir);
        $layout->ensureDirectories();

        return $layout;
    }
}
