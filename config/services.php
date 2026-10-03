<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use QuietLink\Clock\Clock;
use QuietLink\Clock\SystemClock;
use QuietLink\Config\InstanceConfig;
use QuietLink\Paste\PasteService;
use QuietLink\RateLimit\RateLimiter;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Runtime\ServiceFactory;
use QuietLink\Storage\FilesystemPasteStore;
use QuietLink\Storage\PasteStore;
use QuietLink\Storage\StorageLayout;
use QuietLink\Storage\UsageCounter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('env(QUIETLINK_CONFIG_DIR)', '%kernel.project_dir%/config');

    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('QuietLink\\', '../src/')
        ->exclude([
            '../src/Kernel.php',
            '../src/{Cli,Client,Config,Crypto,Encoding,Http,Log,Storage,RateLimit}/',
            '../src/Runtime/{Environment,ServiceFactory}.php',
            '../src/Maintenance/Booter.php',
            '../src/Paste/*Exception.php',
            '../src/Paste/RequestFields.php',
        ]);

    $services->instanceof(QuietLink\Maintenance\ThemeBuilder::class)->tag('quietlink.theme_builder');
    $services->set(RuntimeStatus::class)->args([env('QUIETLINK_CONFIG_DIR')]);
    $services->set(Clock::class, SystemClock::class);
    $services->set(InstanceConfig::class)->factory([service(RuntimeStatus::class), 'config']);
    $services->set(StorageLayout::class)->factory([ServiceFactory::class, 'layout']);
    $services->set(UsageCounter::class)->factory([ServiceFactory::class, 'usage']);
    $services->set(FilesystemPasteStore::class);
    $services->alias(PasteStore::class, FilesystemPasteStore::class);
    $services->set(QuietLink\Storage\IdempotencyStore::class);
    $services->set(QuietLink\Storage\StateFiles::class);
    $services->set(PasteService::class)->factory([ServiceFactory::class, 'pasteService']);
    $services->set(RateLimiter::class)->factory([ServiceFactory::class, 'rateLimiter']);
    $services->set('quietlink.logger', LoggerInterface::class)->factory([ServiceFactory::class, 'logger']);
    $services->alias(LoggerInterface::class, 'quietlink.logger');
    $services->alias('logger', 'quietlink.logger')->public();
};
