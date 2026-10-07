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

    /**
     * One compiled container per application version: an upgrade never boots on the cache of
     * the previous release (§9.6). Instance configuration is read at runtime, never compiled.
     */
    public function getCacheDir(): string
    {
        return self::cacheDirectory($this->getProjectDir(), $this->environment);
    }

    /**
     * var/cache/<env>/<version>, suffixed with the commit of a production archive (BUILD file
     * written by tools/release/build-archive.sh), so that two builds of one version never share
     * a container: production never checks whether it is fresh.
     */
    public static function cacheDirectory(string $projectDir, string $environment): string
    {
        $build = '';
        if (is_file($projectDir . '/BUILD')) {
            $content = @file_get_contents($projectDir . '/BUILD');
            if ($content === false) {
                throw new \RuntimeException('BUILD is not readable: give the PHP account read access to it.');
            }
            $build = trim($content);
        }
        $suffix = preg_match('/^[0-9a-f]{7,40}$/D', $build) === 1 ? '-' . $build : '';

        return $projectDir . '/var/cache/' . $environment . '/' . Version::APP . $suffix;
    }
}
