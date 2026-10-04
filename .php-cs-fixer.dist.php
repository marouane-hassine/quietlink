<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/bin', __DIR__ . '/config', __DIR__ . '/public', __DIR__ . '/src', __DIR__ . '/tests'])
    ->notPath('reference.php')
    ->append([__DIR__ . '/.php-cs-fixer.dist.php', __DIR__ . '/bin/console', __DIR__ . '/bin/quietlink']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
