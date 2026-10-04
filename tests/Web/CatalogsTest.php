<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use QuietLink\Config\ConfigLoader;
use QuietLink\Tests\Support\TempDirectory;
use QuietLink\Web\Catalogs;

#[CoversClass(Catalogs::class)]
#[CoversClass(ConfigLoader::class)]
final class CatalogsTest extends TestCase
{
    private TempDirectory $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TempDirectory();
        $write = fn (string $name, string $json) => file_put_contents($this->tmp->path . '/' . $name, $json);
        $write('en.json', '{"_meta":{"locale":"en","name":"English","dir":"ltr"},"k":"Key"}');
        $write('fr.json', '{"_meta":{"locale":"fr","name":"Français","dir":"ltr"},"k":"Clé"}');
        $write('ar.json', '{"_meta":{"locale":"ar","name":"العربية","dir":"rtl"},"k":"مفتاح"}');
        $write('xx.json', '{"k":"no metadata"}');
        $write('yy.json', '{"_meta":{"locale":"zz","name":"Mismatch","dir":"ltr"}}');
        $write('README.md', 'not a catalogue');
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    /**
     * Adding a language means adding a catalogue, without changing code (§6.6.1): the available
     * locales are the valid catalogues found, English first.
     */
    #[Group('EXG-I18N-007')]
    public function testAvailableLocalesAreTheValidCataloguesFound(): void
    {
        self::assertSame(['en', 'ar', 'fr'], Catalogs::available($this->tmp->path));
    }

    /**
     * Every catalogue declares its direction, applied to the document (§6.6.1).
     */
    #[Group('EXG-I18N-008')]
    public function testEachLocaleExposesItsNameAndDirection(): void
    {
        $catalogs = new Catalogs($this->tmp->path);

        self::assertSame('rtl', $catalogs->direction('ar'));
        self::assertSame('ltr', $catalogs->direction('fr'));
        self::assertSame('ltr', $catalogs->direction('missing'));
        self::assertSame([
            ['code' => 'en', 'name' => 'English', 'dir' => 'ltr'],
            ['code' => 'ar', 'name' => 'العربية', 'dir' => 'rtl'],
        ], $catalogs->locales(['en', 'ar']));
    }

    #[Group('EXG-I18N-007')]
    public function testShippedCataloguesAreAvailableAndEnabledByDefault(): void
    {
        $shipped = Catalogs::available(dirname(__DIR__, 2) . '/translations');

        self::assertContains('fr', $shipped);
        self::assertSame($shipped, ConfigLoader::availableLocales());
    }

    /**
     * A missing or broken translations directory degrades texts, never the service: English
     * stays available so the configuration (and the API) keep working.
     */
    #[Group('EXG-I18N-006')]
    public function testEnglishStaysAvailableWithoutReadableCatalogues(): void
    {
        $empty = $this->tmp->path . '/empty';
        mkdir($empty);

        self::assertSame(['en'], ConfigLoader::availableLocales($empty));
        self::assertSame(['en', 'ar', 'fr'], ConfigLoader::availableLocales($this->tmp->path));
    }
}
