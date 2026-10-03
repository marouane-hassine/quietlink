<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace QuietLink\Cli;

use QuietLink\Crypto\DeletionToken;
use QuietLink\Crypto\Ed25519;
use QuietLink\Crypto\Identifier;
use QuietLink\Crypto\KeyDerivation;
use QuietLink\Crypto\Protocol;
use QuietLink\Encoding\Base64Url;
use QuietLink\Encoding\InvalidEncodingException;
use QuietLink\Storage\PasteId;
use SensitiveParameter;

/**
 * Parsed share (/p/<id>#<key>) or management (/manage/<id>#<token>) link (§8.5).
 * Fingerprints A or D are verified before any network call (§8.2).
 */
final readonly class ShareLink
{
    private function __construct(
        public string $origin,
        public PasteId $id,
        #[SensitiveParameter] public string $secret,
        public bool $management,
    ) {
    }

    public static function parse(#[SensitiveParameter] string $url): self
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || !in_array($parts['scheme'], ['https', 'http'], true)
            || isset($parts['query']) || isset($parts['user'])
            || preg_match('#^/(p|manage)/([A-Za-z0-9_-]{32})$#D', $parts['path'], $match) !== 1) {
            throw new CliException('This is not a valid link.');
        }
        if ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new CliException('Links must use https.');
        }
        $fragment = $parts['fragment'] ?? '';
        $management = $match[1] === 'manage';
        try {
            $id = PasteId::fromEncoded($match[2]);
            $secret = Base64Url::decode($fragment, $management ? DeletionToken::BYTES : Protocol::KEY_BYTES);
        } catch (InvalidEncodingException) {
            throw new CliException('The link is incomplete or damaged.');
        }

        $valid = $management
            ? Identifier::matchesDeletionHash($id->bytes(), DeletionToken::hash($secret))
            : Identifier::matchesAccessKey($id->bytes(), Ed25519::publicKeyFromSeed(KeyDerivation::accessSeed($secret)));
        if (!$valid) {
            throw new CliException('The link has been altered.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return new self($origin, $id, $secret, $management);
    }
}
