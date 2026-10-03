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
     * Fingerprint of the effective configuration recorded in boot.json (§9.5).
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->effective, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
