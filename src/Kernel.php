<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * QuietLink micro-kernel.
 *
 * Configuration is loaded from config/packages/*.php, config/services.php
 * and config/routes.php by MicroKernelTrait.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<FrameworkBundle|TwigBundle>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
    }
}
