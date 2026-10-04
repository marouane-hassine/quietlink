<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Config;

/**
 * Validated, effective instance configuration (§9.5).
 */
final readonly class InstanceConfig
{
    /**
     * @param array<array-key, mixed> $effective normalised configuration tree, without the secret
     */
    public function __construct(
        public AppSettings $app,
        public ThemeSettings $theme,
        public StorageSettings $storage,
        public PasteSettings $paste,
        public HttpSettings $http,
        public UiSettings $ui,
        public ObservabilitySettings $observability,
        public AppSecret $secret,
        private array $effective,
    ) {
    }

    /**
     * Fingerprint of the effective configuration recorded in boot.json (§9.5). It covers the
     * content of the theme tokens file, so editing that file also requires app:boot.
     */
    public function fingerprint(): string
    {
        $fingerprint = hash('sha256', json_encode($this->effective, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($this->theme->customTokensSha256 === null) {
            return $fingerprint;
        }

        return hash('sha256', $fingerprint . "\0" . $this->theme->customTokensSha256);
    }

    /**
     * Effective configuration for `app:config:check`; contains no secret.
     *
     * @return array<array-key, mixed>
     */
    public function describe(): array
    {
        return $this->effective;
    }
}
