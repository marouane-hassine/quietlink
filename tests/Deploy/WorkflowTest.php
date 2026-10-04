<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Static checks of the CI and release workflows.
 */
#[CoversNothing]
final class WorkflowTest extends TestCase
{
    private static function file(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($content);

        return $content;
    }

    /**
     * The release job holds registry credentials and an OIDC token: dependency install scripts
     * must not run there.
     */
    #[Group('EXG-DEPLOY-001')]
    public function testReleaseJobRunsNoDependencyInstallScripts(): void
    {
        $release = self::file('.github/workflows/release.yml');
        preg_match_all('/^\s*(?:run:\s*|\(?)?.*\b(npm ci|composer install)\b.*$/m', $release, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[0] as $line) {
            self::assertMatchesRegularExpression('/--ignore-scripts|--no-scripts/', $line, trim($line));
        }
    }

    /**
     * The "no database" check catches images with a registry, namespace or quotes too.
     */
    #[Group('EXG-DEPLOY-001')]
    public function testNoDatabaseCheckCatchesQualifiedImageNames(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        // YAML double-quoted scalar: the doubled backslash in the file is one for grep.
        self::assertStringContainsString("grep -Eiq '^\\\\s*image:.*(postgres|mysql|mariadb|redis|valkey|mongo)' compose.yaml", $ci);
        $pattern = '/^\s*image:.*(postgres|mysql|mariadb|redis|valkey|mongo)/im';
        foreach (['    image: docker.io/library/postgres:16', '    image: "redis:7"', '    image: bitnami/mariadb'] as $line) {
            self::assertMatchesRegularExpression($pattern, $line);
        }
    }

    /**
     * Every third-party image or action used by CI is pinned by digest or commit.
     */
    #[Group('EXG-DEPLOY-001')]
    public function testSecretScannerImageIsPinnedByDigest(): void
    {
        self::assertMatchesRegularExpression('#zricethezav/gitleaks:v[\d.]+@sha256:[0-9a-f]{64}#', self::file('.github/workflows/ci.yml'));
    }

    /**
     * A version with a pre-release suffix (1.0.0-beta.1) is published as a GitHub pre-release
     * and never marked as the latest release.
     */
    #[Group('EXG-DEPLOY-001')]
    public function testPreReleaseTagsArePublishedAsPreReleases(): void
    {
        $release = self::file('.github/workflows/release.yml');
        self::assertStringContainsString("prerelease: \${{ contains(github.ref_name, '-') }}", $release);
        self::assertStringContainsString("make_latest: \${{ !contains(github.ref_name, '-') }}", $release);
    }
}
