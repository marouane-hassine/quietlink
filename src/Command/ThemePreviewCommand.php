<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Command;

use QuietLink\Config\InvalidConfigException;
use QuietLink\Runtime\RuntimeStatus;
use QuietLink\Theme\TokenThemeBuilder;
use QuietLink\Web\Assets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Static preview of every component and state with the configured theme, before activation
 * (§6.5, Could). Uses dummy texts only and no script.
 */
#[AsCommand(name: 'app:theme:preview', description: 'Write a static page showing all components and states with the configured theme.')]
final class ThemePreviewCommand extends Command
{
    public function __construct(
        private readonly RuntimeStatus $status,
        private readonly Assets $assets,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
        #[Autowire(env: 'QUIETLINK_CONFIG_DIR')] private readonly string $configDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Directory to write the preview into');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('output');
        if (!is_string($target) || $target === '') {
            OutputFormat::line($output, 'error', 'Use --output=<directory>.');

            return Command::FAILURE;
        }
        try {
            $config = $this->status->config();
        } catch (InvalidConfigException $e) {
            foreach ($e->errors as $error) {
                OutputFormat::line($output, 'error', $error);
            }

            return Command::FAILURE;
        }
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            $output->writeln('<error>The output directory cannot be created.</error>');

            return Command::FAILURE;
        }

        $styles = [];
        foreach ($this->assets->entry()['css'] as $css) {
            $source = $this->publicDir . $css;
            if (is_file($source)) {
                copy($source, $target . '/' . basename($css));
                $styles[] = basename($css);
            }
        }
        if ($styles === []) {
            $output->writeln('<error>Frontend assets are missing: run npm run build first.</error>');

            return Command::FAILURE;
        }
        if ($config->theme->customTokensFile !== null) {
            try {
                $data = json_decode((string) file_get_contents($this->configDir . '/themes/' . $config->theme->customTokensFile), true, 8, JSON_THROW_ON_ERROR);
                file_put_contents($target . '/tokens.css', TokenThemeBuilder::compile(is_array($data) ? $data : []));
                $styles[] = 'tokens.css';
            } catch (Throwable $e) {
                OutputFormat::line($output, 'error', 'Invalid theme tokens: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        file_put_contents($target . '/index.html', self::page($config->app->name, $styles, 'light'));
        file_put_contents($target . '/dark.html', self::page($config->app->name, $styles, 'dark'));
        $output->writeln('theme preview written to ' . $target . '/index.html and dark.html');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $styles
     */
    private static function page(string $name, array $styles, string $theme): string
    {
        $links = implode("\n", array_map(static fn (string $file): string => '    <link rel="stylesheet" href="' . htmlspecialchars($file) . '">', $styles));
        $title = htmlspecialchars($name);
        $section = static fn (string $theme): string => <<<HTML
            <section data-preview-theme="{$theme}">
                <h2 class="page-title">Theme: {$theme}</h2>
                <p class="lead">Secondary text on the page background. <a href="#">Accent link</a></p>
                <div class="field"><label class="field-label" for="e-{$theme}">Text field</label><textarea id="e-{$theme}" class="editor" rows="3">Dummy content for the preview</textarea></div>
                <div class="field"><label for="s-{$theme}">Select</label><select id="s-{$theme}"><option>Option</option></select></div>
                <div class="field field-check"><input type="checkbox" id="c-{$theme}" checked><label for="c-{$theme}">Checkbox</label></div>
                <div class="presets"><button class="chip" aria-pressed="true">Selected chip</button><button class="chip">Chip</button></div>
                <div class="button-row"><button class="button button-primary">Primary action</button><button class="button button-secondary">Secondary</button><button class="button button-tertiary">Tertiary</button><button class="button button-danger">Danger</button><button class="button button-primary" disabled>Disabled</button></div>
                <p class="success">Success state.</p>
                <p class="warning">Security warning.</p>
                <p class="error">Error state.</p>
                <p class="notice">Notice.</p>
                <p class="banner">Banner.</p>
                <div class="reader"><pre class="plain">Dummy decrypted content block</pre></div>
                <section class="danger-zone"><h2>Danger zone</h2><p>Destructive actions.</p></section>
            </section>
            HTML;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en" dir="ltr" data-theme="{$theme}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title} · theme preview</title>
            {$links}
            </head>
            <body>
                <header class="site-header"><span class="brand">{$title}</span><span>Theme preview — dummy content only — <a href="index.html">light</a> · <a href="dark.html">dark</a></span></header>
                <main>
            {$section($theme)}
                </main>
            </body>
            </html>
            HTML;
    }
}
