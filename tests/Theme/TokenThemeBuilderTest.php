<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Theme;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Tests\Support\TestInstance;
use QuietLink\Theme\TokenThemeBuilder;

#[CoversClass(TokenThemeBuilder::class)]
final class TokenThemeBuilderTest extends TestCase
{
    #[Group('EXG-SEC-031')]
    public function testAllowlistedTokensCompileToCss(): void
    {
        $css = TokenThemeBuilder::compile(['light' => ['color-primary' => '#AA3300', 'radius' => '8px'], 'dark' => ['color-primary-text' => '#f0a07f']]);

        self::assertStringContainsString('--ql-color-primary: #aa3300;', $css);
        self::assertStringContainsString('--ql-radius: 8px;', $css);
        self::assertStringContainsString(':root[data-theme="dark"]', $css);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidThemes(): iterable
    {
        yield 'external url' => [['light' => ['color-primary' => 'url(https://evil.example.test/x)']]];
        yield 'css injection' => [['light' => ['color-primary' => '#000000; } body { display:none']]];
        yield 'unknown token' => [['light' => ['security-danger-text' => '#000000']]];
        yield 'unknown section' => [['print' => []]];
        yield 'length out of range' => [['light' => ['font-size' => '4rem']]];
        yield 'not a string' => [['light' => ['radius' => 4]]];
    }

    /**
     * @param array<string, mixed> $theme
     */
    #[DataProvider('invalidThemes')]
    #[Group('EXG-SEC-035')]
    #[Group('EXG-SEC-036')]
    #[Group('EXG-SEC-037')]
    public function testInvalidThemesAreRefused(array $theme): void
    {
        $this->expectException(InvalidArgumentException::class);
        TokenThemeBuilder::compile($theme);
    }

    #[Group('EXG-SEC-030')]
    public function testBuildWritesAHashedFileAndAManifest(): void
    {
        $tmp = new TempDirectory();
        try {
            mkdir($tmp->path . '/config/themes', 0700, true);
            file_put_contents($tmp->path . '/config/themes/brand.json', '{"light":{"color-primary":"#123456"}}');
            $config = TestInstance::config($tmp, ['theme' => ['custom_tokens_file' => 'brand.json']]);
            mkdir($config->storage->generatedAssetsDir, 0755, true);

            (new TokenThemeBuilder($tmp->path . '/config'))->build($config);

            $url = TokenThemeBuilder::stylesheet($config);
            self::assertNotNull($url);
            self::assertMatchesRegularExpression('#^/themes/generated/tokens\.[0-9a-f]{16}\.css$#', $url);
            self::assertStringContainsString('#123456', (string) file_get_contents($config->storage->generatedAssetsDir . '/' . basename($url)));
        } finally {
            $tmp->remove();
        }
    }
}
