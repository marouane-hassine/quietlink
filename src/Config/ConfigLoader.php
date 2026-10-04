<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

use QuietLink\Crypto\Argon2id;
use QuietLink\Web\Catalogs;
use Throwable;

/**
 * Loads defaults, config.php and config.local.php, then validates the result (§9.5).
 *
 * Unknown keys and wrong types are errors. Secrets come only from the environment.
 */
final class ConfigLoader
{
    public const FALLBACK_LOCALE = 'en';

    /** @var array<string, list<string>> */
    private static array $localeMemo = [];
    /** Built-in themes; operators customize the active one with theme.custom_tokens_file. */
    public const AVAILABLE_THEMES = ['default'];
    public const TEMPLATES = ['credentials', 'api-token', 'wifi', 'ssh-key', 'database', 'env-vars', 'temporary-access', 'incident'];
    public const RATE_LIMIT_BUCKETS = ['create', 'create_replay', 'challenge', 'open', 'status', 'consume', 'delete', 'health', 'open_per_paste', 'status_per_paste'];
    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Locales of the catalogues shipped in translations/ (§6.6.1: a new language is a new
     * catalogue, no code change).
     *
     * @return list<string>
     */
    public static function availableLocales(?string $directory = null): array
    {
        $directory ??= dirname(__DIR__, 2) . '/translations';
        // Computed once per process: the configuration is loaded on every request.
        if (!isset(self::$localeMemo[$directory])) {
            $found = Catalogs::available($directory);
            // English always counts: a missing or broken catalogue directory degrades the texts
            // (keys shown), never the whole service.
            self::$localeMemo[$directory] = in_array(self::FALLBACK_LOCALE, $found, true) ? $found : [self::FALLBACK_LOCALE, ...$found];
        }

        return self::$localeMemo[$directory];
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Absolute paths as given, relative ones resolved from the project root; "." segments and
     * doubled slashes are removed so that comparisons see the canonical spelling.
     */
    private static function resolvePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : self::projectRoot() . '/' . $path;
        $segments = array_filter(explode('/', $absolute), static fn (string $s): bool => $s !== '' && $s !== '.');

        return '/' . implode('/', $segments);
    }

    /**
     * True when the path is the web root or lies below it, also through a symbolic link on an
     * existing ancestor or a letter-case variant (case-insensitive filesystems).
     */
    private static function isInsideWebRoot(string $path): bool
    {
        $webRoot = self::projectRoot() . '/public';
        $candidates = [[$path, $webRoot]];
        $ancestor = $path;
        while (!file_exists($ancestor) && $ancestor !== '/') {
            $ancestor = dirname($ancestor);
        }
        $realAncestor = realpath($ancestor);
        $realWebRoot = realpath($webRoot);
        if ($realAncestor !== false && $realWebRoot !== false) {
            $candidates[] = [$realAncestor . substr($path, strlen($ancestor)), $realWebRoot];
        }
        foreach ($candidates as [$candidate, $root]) {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
            if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The four storage directories: each one as configured, or derived from storage.data_dir.
     *
     * @return array<string, string>
     */
    private static function storageDirs(TreeReader $r): array
    {
        $data = self::resolvePath($r->string('storage.data_dir'));
        $dirs = [];
        foreach (self::DATA_SUBDIRS as $key => $sub) {
            $configured = $r->nullableString('storage.' . $key);
            $dirs[$key] = $configured === null || $configured === '' ? $data . '/' . $sub : self::resolvePath($configured);
        }

        return $dirs;
    }

    /**
     * Versioned defaults (§9.5, ADR-0007 for rate limits).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'QuietLink',
                'public_url' => null,
                'source_url' => 'https://github.com/marouane-hassine/quietlink',
                // Every shipped catalogue (translations/*.json) is enabled unless restricted.
                'enabled_locales' => null,
            ],
            'theme' => [
                'name' => 'default',
                'custom_tokens_file' => null,
            ],
            'storage' => [
                'driver' => 'filesystem',
                // Relative to the project root; the four directories below derive from it when
                // null (spec §9.4.1, v0.19).
                'data_dir' => 'datas',
                'root_dir' => null,
                'idempotency_dir' => null,
                'ratelimit_dir' => null,
                'state_dir' => null,
                'generated_assets_dir' => '/var/lib/quietlink-generated',
                'max_total_bytes' => 10737418240,
                'max_items' => 100000,
                'min_free_bytes' => 1073741824,
                'min_free_inodes_percent' => 10,
                'allow_unsupported_fs' => false,
            ],
            'paste' => [
                'default_expiration' => '1d',
                'allowed_expirations' => ['5m', '1h', '1d', '7d', '30d'],
                'allow_forever' => false,
                'allow_read_once' => true,
                'allow_passphrase' => true,
                'max_envelope_bytes' => 1048576,
                'max_metadata_bytes' => 4096,
                'max_retention' => '30d',
                'max_unconfirmed_opens' => 3,
                'read_once_reservation_ttl' => 60,
                'idempotency_max_ttl' => '24h',
            ],
            'http' => [
                'max_request_bytes' => 1441792,
                'ratelimit_ipv6_prefix' => 64,
                'trusted_proxies' => [],
                'cors_allowed_origins' => [],
                'hsts_max_age' => 31536000,
                'rate_limits' => [
                    'create' => ['limit' => 30, 'interval' => 600],
                    'create_replay' => ['limit' => 120, 'interval' => 600],
                    'challenge' => ['limit' => 120, 'interval' => 60],
                    'open' => ['limit' => 60, 'interval' => 60],
                    'status' => ['limit' => 60, 'interval' => 60],
                    'consume' => ['limit' => 60, 'interval' => 60],
                    'delete' => ['limit' => 30, 'interval' => 600],
                    'health' => ['limit' => 60, 'interval' => 60],
                    'open_per_paste' => ['limit' => 20, 'interval' => 60],
                    'status_per_paste' => ['limit' => 30, 'interval' => 60],
                ],
            ],
            'ui' => [
                'dark_mode' => 'auto',
                'templates' => self::TEMPLATES,
                'enable_qr_code' => true,
                'allow_print' => false,
                'allow_export' => false,
                'enable_manifest' => false,
            ],
            'log' => [
                'level' => 'info',
                'retention' => '14d',
            ],
            'metrics' => [
                'enabled' => false,
            ],
        ];
    }

    /**
     * Keys whose value may be null in addition to their default type.
     */
    private const NULLABLE = ['app.public_url', 'app.enabled_locales', 'theme.custom_tokens_file', 'paste.max_retention', 'storage.root_dir', 'storage.idempotency_dir', 'storage.ratelimit_dir', 'storage.state_dir'];

    /** Storage directories derived from storage.data_dir when not set: key => sub-directory. */
    private const DATA_SUBDIRS = ['root_dir' => 'pastes', 'idempotency_dir' => 'idempotency', 'ratelimit_dir' => 'ratelimit', 'state_dir' => 'state'];

    /**
     * Keys whose value is a free-form list (not merged key by key).
     */
    private const LISTS = [
        'app.enabled_locales', 'paste.allowed_expirations', 'http.trusted_proxies',
        'http.cors_allowed_origins', 'ui.templates',
    ];

    /**
     * @param array<array-key, mixed> $env process variables ($_SERVER + getenv())
     *
     * @throws InvalidConfigException
     */
    public static function load(string $configDir, array $env): InstanceConfig
    {
        $errors = [];
        $tree = self::defaults();

        $main = $configDir . '/config.php';
        if (!is_file($main)) {
            throw new InvalidConfigException(['config/config.php is missing; copy config/config.php.example.']);
        }
        foreach ([$main, $configDir . '/config.local.php'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            try {
                $values = (static fn (string $path): mixed => require $path)($file);
            } catch (Throwable $e) {
                // Class name only: the message could quote file content.
                $errors[] = sprintf('%s could not be loaded (%s).', basename($file), $e::class);
                continue;
            }
            if (!is_array($values)) {
                $errors[] = sprintf('%s must return an array.', basename($file));
                continue;
            }
            $tree = self::merge($tree, $values, '', $errors);
        }

        // null: every shipped catalogue, so new languages of later releases are enabled too.
        if (is_array($tree['app'] ?? null) && array_key_exists('enabled_locales', $tree['app']) && $tree['app']['enabled_locales'] === null) {
            $tree['app']['enabled_locales'] = self::availableLocales();
        }
        $secret = self::secret($env, $errors);
        $config = $errors === [] ? self::build($tree, $configDir, $errors) : null;

        if ($errors !== [] || $config === null || $secret === null) {
            throw new InvalidConfigException($errors);
        }

        return new InstanceConfig(...[...$config, 'secret' => $secret, 'effective' => $tree]);
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @param list<string>            $errors
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override, string $prefix, array &$errors): array
    {
        foreach ($override as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (!array_key_exists($key, $base)) {
                $errors[] = sprintf('Unknown configuration key "%s".', $path);
                continue;
            }
            $default = $base[$key];
            if (is_array($default) && !in_array($path, self::LISTS, true)) {
                if (!is_array($value)) {
                    $errors[] = sprintf('"%s" must be an array.', $path);
                    continue;
                }
                $base[$key] = self::merge($default, $value, $path, $errors);
                continue;
            }
            // A list whose default is null (every shipped language) is still typed as a list.
            $typed = $default === null && in_array($path, self::LISTS, true) ? [] : $default;
            if ($value === null ? !in_array($path, self::NULLABLE, true) : !self::sameType($typed, $value)) {
                $errors[] = sprintf('"%s" has an invalid type.', $path);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private static function sameType(mixed $default, mixed $value): bool
    {
        return match (true) {
            $default === null => is_string($value),
            // Every free-form list holds strings only (locales, codes, addresses, origins, templates).
            is_array($default) => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
            default => get_debug_type($default) === get_debug_type($value),
        };
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string>            $errors
     */
    private static function secret(array $env, array &$errors): ?AppSecret
    {
        $inline = $env['QUIETLINK_APP_SECRET'] ?? null;
        $file = $env['QUIETLINK_APP_SECRET_FILE'] ?? null;
        $inline = is_string($inline) && $inline !== '' ? $inline : null;
        $file = is_string($file) && $file !== '' ? $file : null;

        if ($inline !== null && $file !== null) {
            $errors[] = 'Set either QUIETLINK_APP_SECRET or QUIETLINK_APP_SECRET_FILE, not both.';

            return null;
        }
        if ($file !== null) {
            $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
            if ($content === false) {
                $errors[] = 'QUIETLINK_APP_SECRET_FILE does not point to a readable file.';

                return null;
            }
            $inline = trim($content);
        }
        if ($inline === null) {
            $errors[] = 'QUIETLINK_APP_SECRET (or QUIETLINK_APP_SECRET_FILE) is required; generate one with app:secret:generate.';

            return null;
        }

        $bytes = base64_decode($inline, true);
        if ($bytes === false || strlen($bytes) < 32) {
            $errors[] = 'QUIETLINK_APP_SECRET must be standard base64 decoding to at least 32 bytes.';

            return null;
        }

        return new AppSecret($bytes);
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param list<string>            $errors
     *
     * @return array{app: AppSettings, theme: ThemeSettings, storage: StorageSettings, paste: PasteSettings, http: HttpSettings, ui: UiSettings, observability: ObservabilitySettings}|null
     */
    private static function build(array $tree, string $configDir, array &$errors): ?array
    {
        $r = new TreeReader($tree);

        if (trim($r->string('app.name')) === '') {
            $errors[] = '"app.name" must not be empty.';
        }
        $publicUrl = $r->nullableString('app.public_url');
        if ($publicUrl === null || !self::isValidPublicUrl($publicUrl)) {
            $errors[] = '"app.public_url" must be an https origin without path, query or fragment (http only for localhost).';
        }
        if (!self::isValidPublicUrl($r->string('app.source_url'), true)) {
            $errors[] = '"app.source_url" must be an https URL of the deployed source code (AGPL-3.0 section 13).';
        }
        $locales = $r->stringList('app.enabled_locales');
        if (!in_array(self::FALLBACK_LOCALE, $locales, true) || array_diff($locales, self::availableLocales()) !== [] || count(array_unique($locales)) !== count($locales)) {
            $errors[] = sprintf('"app.enabled_locales" must include "en" and only contain: %s.', implode(', ', self::availableLocales()));
        }

        if (!in_array($r->string('theme.name'), self::AVAILABLE_THEMES, true)) {
            $errors[] = sprintf('"theme.name" must be one of: %s.', implode(', ', self::AVAILABLE_THEMES));
        }
        $tokensFile = $r->nullableString('theme.custom_tokens_file');
        $tokensDigest = null;
        if ($tokensFile !== null && !self::isSafeThemeFile($tokensFile, $configDir)) {
            $errors[] = '"theme.custom_tokens_file" must be a relative .json file inside config/themes/.';
        } elseif ($tokensFile !== null) {
            $tokensDigest = @hash_file('sha256', $configDir . '/themes/' . $tokensFile);
            if ($tokensDigest === false) {
                $errors[] = '"theme.custom_tokens_file" is not readable.';
            }
        }

        $dataDir = trim($r->string('storage.data_dir'));
        if ($dataDir === '' || self::resolvePath($dataDir) === '/' || self::resolvePath($dataDir) === self::projectRoot()) {
            $errors[] = '"storage.data_dir" must name a dedicated directory, not the filesystem or project root.';
        }
        foreach (['data_dir' => $r->string('storage.data_dir')] + self::storageDirs($r) + ['generated_assets_dir' => $r->string('storage.generated_assets_dir')] as $dir => $path) {
            if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
                $errors[] = sprintf('"storage.%s" must not contain "..".', $dir);
            } elseif (self::isInsideWebRoot(self::resolvePath($path))) {
                $errors[] = sprintf('"storage.%s" must be outside the web root (public/).', $dir);
            }
        }
        if ($r->string('storage.driver') !== 'filesystem') {
            $errors[] = '"storage.driver" only supports "filesystem".';
        }
        foreach (['max_total_bytes', 'max_items', 'min_free_bytes'] as $key) {
            if ($r->int('storage.' . $key) < 1) {
                $errors[] = sprintf('"storage.%s" must be positive.', $key);
            }
        }
        $inodes = $r->int('storage.min_free_inodes_percent');
        if ($inodes < 0 || $inodes > 50) {
            $errors[] = '"storage.min_free_inodes_percent" must be between 0 and 50.';
        }

        $allowed = $r->stringList('paste.allowed_expirations');
        $default = $r->string('paste.default_expiration');
        $allowForever = $r->bool('paste.allow_forever');
        $retentionRaw = $r->nullableString('paste.max_retention');
        $retention = $retentionRaw === null ? null : Duration::parse($retentionRaw);
        if ($retentionRaw !== null && $retention === null) {
            $errors[] = '"paste.max_retention" must use the <integer><m|h|d> format.';
        }
        if ($allowed === [] || array_diff($allowed, array_keys(Duration::EXPIRATION_SECONDS)) !== []) {
            $errors[] = '"paste.allowed_expirations" may only contain 5m, 1h, 1d, 7d, 30d ("never" is enabled by allow_forever).';
        }
        if (!in_array($default, $allowed, true)) {
            $errors[] = '"paste.default_expiration" must belong to paste.allowed_expirations.';
        }
        if ($allowForever && $retentionRaw !== null) {
            $errors[] = '"paste.allow_forever" requires paste.max_retention = null.';
        }
        if ($retention !== null) {
            foreach ($allowed as $code) {
                if ((Duration::expirationSeconds($code) ?? 0) > $retention) {
                    $errors[] = sprintf('Expiration "%s" exceeds paste.max_retention.', $code);
                }
            }
        }
        $opens = $r->int('paste.max_unconfirmed_opens');
        if ($opens < 1 || $opens > 10) {
            $errors[] = '"paste.max_unconfirmed_opens" must be between 1 and 10.';
        }
        $reservation = $r->int('paste.read_once_reservation_ttl');
        if ($reservation < 30 || $reservation > 300) {
            $errors[] = '"paste.read_once_reservation_ttl" must be between 30 and 300 seconds.';
        }
        $idempotency = Duration::parse($r->string('paste.idempotency_max_ttl'));
        if ($idempotency === null || $idempotency < 3600 || $idempotency > 7 * 86400) {
            $errors[] = '"paste.idempotency_max_ttl" must be between 1h and 7d.';
        }
        $envelope = $r->int('paste.max_envelope_bytes');
        $metadata = $r->int('paste.max_metadata_bytes');
        $sizesValid = $envelope >= PasteSettings::MIN_ENVELOPE_BYTES && $envelope <= PasteSettings::MAX_ENVELOPE_BYTES
            && $metadata >= PasteSettings::MIN_METADATA_BYTES && $metadata <= PasteSettings::MAX_METADATA_BYTES;
        if (!$sizesValid) {
            $errors[] = sprintf(
                '"paste.max_envelope_bytes" must be between %d and %d and "paste.max_metadata_bytes" between %d and %d.',
                PasteSettings::MIN_ENVELOPE_BYTES,
                PasteSettings::MAX_ENVELOPE_BYTES,
                PasteSettings::MIN_METADATA_BYTES,
                PasteSettings::MAX_METADATA_BYTES,
            );
        }
        $ciphertext = $envelope + 16;

        $maxRequest = $r->int('http.max_request_bytes');
        // Only computed on bounded sizes, so the arithmetic can never overflow.
        $requiredRequest = $sizesValid ? (int) ceil($ciphertext * 4 / 3) + (int) ceil($metadata * 4 / 3) + 16384 : 0;
        if ($maxRequest < $requiredRequest) {
            $errors[] = sprintf('"http.max_request_bytes" must be at least %d for the configured sizes.', $requiredRequest);
        }
        $prefix = $r->int('http.ratelimit_ipv6_prefix');
        if ($prefix < 48 || $prefix > 64) {
            $errors[] = '"http.ratelimit_ipv6_prefix" must be between 48 and 64.';
        }
        $rateLimits = [];
        foreach ($r->map('http.rate_limits') as $bucket => $settings) {
            $limit = is_array($settings) ? ($settings['limit'] ?? null) : null;
            $interval = is_array($settings) ? ($settings['interval'] ?? null) : null;
            if (!in_array($bucket, self::RATE_LIMIT_BUCKETS, true) || !is_int($limit) || !is_int($interval)
                || $limit < 1 || $interval < 1 || $interval > 86400 || count($settings) !== 2) {
                $errors[] = sprintf('"http.rate_limits.%s" must be a known bucket with positive integer limit and interval (≤ 86400 s).', $bucket);
                continue;
            }
            $rateLimits[$bucket] = new RateLimitSettings($limit, $interval);
        }
        foreach ($r->stringList('http.cors_allowed_origins') as $origin) {
            if (!self::isValidCorsOrigin($origin)) {
                $errors[] = '"http.cors_allowed_origins" entries must be exact lowercase origins "https://host[:port]" without path, trailing slash or wildcard (http only for localhost).';
            }
        }
        foreach ($r->stringList('http.trusted_proxies') as $proxy) {
            if (!self::isValidProxy($proxy)) {
                $errors[] = '"http.trusted_proxies" entries must be IP addresses or CIDR ranges.';
            }
        }

        if ($r->int('http.hsts_max_age') < 0) {
            $errors[] = '"http.hsts_max_age" must be 0 (disabled) or a positive number of seconds.';
        }

        $darkMode = $r->string('ui.dark_mode');
        if (!in_array($darkMode, ['auto', 'light', 'dark'], true)) {
            $errors[] = '"ui.dark_mode" must be auto, light or dark.';
        }
        $templates = $r->stringList('ui.templates');
        if (array_diff($templates, self::TEMPLATES) !== [] || count(array_unique($templates)) !== count($templates)) {
            $errors[] = sprintf('"ui.templates" may only contain, without duplicates: %s.', implode(', ', self::TEMPLATES));
        }

        $logLevel = $r->string('log.level');
        if (!in_array($logLevel, self::LOG_LEVELS, true)) {
            $errors[] = '"log.level" must be a PSR-3 level.';
        }
        if (Duration::parse($r->string('log.retention')) === null) {
            $errors[] = '"log.retention" must use the <integer><m|h|d> format.';
        }

        if ($errors !== [] || $publicUrl === null || $idempotency === null) {
            return null;
        }

        return [
            'app' => new AppSettings($r->string('app.name'), rtrim($publicUrl, '/'), $locales, $r->string('app.source_url')),
            'theme' => new ThemeSettings($r->string('theme.name'), $tokensFile, $tokensDigest === false ? null : $tokensDigest),
            'storage' => new StorageSettings(
                self::storageDirs($r)['root_dir'],
                self::storageDirs($r)['idempotency_dir'],
                self::storageDirs($r)['ratelimit_dir'],
                self::storageDirs($r)['state_dir'],
                self::resolvePath($r->string('storage.generated_assets_dir')),
                $r->int('storage.max_total_bytes'),
                $r->int('storage.max_items'),
                $r->int('storage.min_free_bytes'),
                $inodes,
                $r->bool('storage.allow_unsupported_fs'),
            ),
            'paste' => new PasteSettings(
                $default,
                $allowed,
                $allowForever,
                $r->bool('paste.allow_read_once'),
                $r->bool('paste.allow_passphrase') && self::passphraseSupported(),
                $envelope,
                $ciphertext,
                $metadata,
                $retention,
                $opens,
                $reservation,
                $idempotency,
            ),
            'http' => new HttpSettings(
                $maxRequest,
                $prefix,
                $r->stringList('http.trusted_proxies'),
                $rateLimits,
                $r->stringList('http.cors_allowed_origins'),
                $r->int('http.hsts_max_age'),
            ),
            'ui' => new UiSettings(
                $darkMode,
                $templates,
                $r->bool('ui.enable_qr_code'),
                $r->bool('ui.allow_print'),
                $r->bool('ui.allow_export'),
                $r->bool('ui.enable_manifest'),
            ),
            'observability' => new ObservabilitySettings($logLevel, $r->string('log.retention'), $r->bool('metrics.enabled')),
        ];
    }

    private static function isValidPublicUrl(string $url, bool $allowPath = false): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!$allowPath && isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * An origin exactly as a browser serializes it in the Origin header (RFC 6454): lowercase
     * scheme and host, no default port, no path, no trailing slash, no wildcard. Plain http is
     * accepted only for loopback hosts, like app.public_url.
     */
    private static function isValidCorsOrigin(string $origin): bool
    {
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        $pattern = '#^(https?)://(' . $label . '(?:\.' . $label . ')*|\[[0-9a-f:.]+\])(?::([1-9][0-9]{0,4}))?$#D';
        if (preg_match($pattern, $origin, $match) !== 1) {
            return false;
        }
        [, $scheme, $host] = $match;
        $port = isset($match[3]) ? (int) $match[3] : null;
        if ($port !== null && ($port > 65535 || $port === ($scheme === 'https' ? 443 : 80))) {
            return false;
        }
        if (str_starts_with($host, '[')) {
            $packed = @inet_pton(substr($host, 1, -1));
            // Only the canonical (compressed) IPv6 form can match a browser Origin.
            if ($packed === false || strlen($packed) !== 16 || inet_ntop($packed) !== substr($host, 1, -1)) {
                return false;
            }
        } elseif (preg_match('/^[0-9.]+$/D', $host) === 1 && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return $scheme === 'https' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * An IPv4 or IPv6 address, optionally followed by a decimal prefix length within the
     * address family bounds (0–32 or 0–128), without leading zeros.
     */
    private static function isValidProxy(string $proxy): bool
    {
        [$address, $prefix] = str_contains($proxy, '/') ? explode('/', $proxy, 2) : [$proxy, null];
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $max = 32;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $max = 128;
        } else {
            return false;
        }
        if ($prefix === null) {
            return true;
        }

        return preg_match('/^(?:0|[1-9]\d{0,2})$/D', $prefix) === 1 && (int) $prefix <= $max;
    }

    private static function isSafeThemeFile(string $file, string $configDir): bool
    {
        if (preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.json$#D', $file) !== 1) {
            return false;
        }
        $themes = realpath($configDir . '/themes');
        $path = realpath($configDir . '/themes/' . $file);

        return $themes !== false && $path !== false && str_starts_with($path, $themes . '/');
    }

    /**
     * Passphrase creation needs a validated Argon2id implementation (no silent downgrade, §8.3).
     */
    private static function passphraseSupported(): bool
    {
        return defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13') && class_exists(Argon2id::class);
    }
}
