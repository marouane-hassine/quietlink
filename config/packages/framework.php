<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'http_method_override' => false,
        // Also ignore the X-HTTP-Method-Override header: a POST must never run as DELETE.
        'allowed_http_method_override' => [],
        'handle_all_throwables' => true,
        'php_errors' => ['log' => true],
        'session' => false,
        // QuietLink reads QUIETLINK_APP_SECRET(_FILE) only: no Symfony vault, no secrets:* commands.
        'secrets' => false,
        'router' => ['utf8' => true],
        'test' => $container->env() === 'test',
    ]);
};
