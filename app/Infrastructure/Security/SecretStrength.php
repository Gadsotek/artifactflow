<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * The single definition of what counts as a real deployment secret: not empty,
 * not a known placeholder, and at least {@see MINIMUM_SECRET_BYTES} bytes long.
 * A base64: value is measured after decoding; every other value is measured as
 * the raw string. That raw-string path is a length floor, not a decoded-entropy
 * measurement -- normalized() deliberately does NOT hex-decode, because those
 * bytes are also the HMAC signing-key material (see ArtifactPreviewUrl) and
 * decoding would change every signature. Operator-provisioned secrets
 * (key:generate, ensure-reverb-keys) are full-entropy random, so the 32-byte
 * string floor sits well above 128 bits of real entropy for them.
 * ProductionSecurityConfiguration (boot gate), DeploymentDoctor, the install
 * wizard, signed-URL signing, and Reverb config validation must all consume
 * this class so the rule can never drift between them.
 */
final readonly class SecretStrength
{
    public const int MINIMUM_SECRET_BYTES = 32;

    /**
     * @var list<string>
     */
    private const array PLACEHOLDER_MARKERS = [
        'replace-with',
        'replace_me',
        'replace-me',
        'change-me',
        'changeme',
        'placeholder',
    ];

    /**
     * Secrets that ship in this repository's own fixtures. They are valid 32-byte
     * keys, so length alone would bless them, but reusing a value published in the
     * source tree as a real deployment secret is exactly the footgun the boot gate
     * must catch. Only fixtures NOT also used as a live PHP-test value belong here:
     * the shared test signing key, for example, is exercised by the suite in every
     * environment and cannot be blocked without breaking it.
     *
     * @var list<string>
     */
    private const array PUBLISHED_FIXTURE_SECRETS = [
        // docker-compose.yml E2E_APP_KEY default.
        'base64:YXJ0aWZhY3RmbG93LWUyZS1hcHAta2V5LTAwMDAwMDA=',
    ];

    /**
     * Repo-published signing-key fixtures. Unlike {@see PUBLISHED_FIXTURE_SECRETS}
     * these ARE used as live PHP-test signing values (tests/TestCase.php and the
     * docker-compose e2e services), so they must NOT feed isStrong() -- doing so
     * would break HMAC signing across the suite. The production boot gate rejects
     * them directly instead, and that gate never runs under APP_ENV=testing.
     *
     * @var list<string>
     */
    private const array PUBLISHED_SIGNING_KEY_FIXTURES = [
        // docker-compose.yml E2E_ARTIFACT_URL_SIGNING_KEY default; tests/TestCase.php.
        'artifact-preview-test-signing-key',
    ];

    public static function isPlaceholder(string $secret): bool
    {
        $normalized = strtolower(trim($secret));

        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublishedFixtureSecret(string $secret): bool
    {
        return self::matchesAnyFixture($secret, self::PUBLISHED_FIXTURE_SECRETS);
    }

    /**
     * Whether the value is a signing key published in this repository's source
     * tree. Consumed only by the production boot gate (never by isStrong), so a
     * real deployment cannot reuse the public e2e signing key while the test
     * suite keeps using it for live HMAC signing.
     */
    public static function isPublishedSigningKeyFixture(string $secret): bool
    {
        return self::matchesAnyFixture($secret, self::PUBLISHED_SIGNING_KEY_FIXTURES);
    }

    /**
     * @param list<string> $fixtures
     */
    private static function matchesAnyFixture(string $secret, array $fixtures): bool
    {
        $candidate = trim($secret);
        $candidateBytes = self::normalized($candidate);

        foreach ($fixtures as $fixture) {
            if (hash_equals($fixture, $candidate)) {
                return true;
            }

            $fixtureBytes = self::normalized($fixture);

            if ($candidateBytes !== null && $fixtureBytes !== null && hash_equals($fixtureBytes, $candidateBytes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raw secret bytes: base64-decoded when the value uses the base64: prefix,
     * null when the value is empty or the base64 payload is invalid.
     */
    public static function normalized(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $secret;
    }

    public static function isStrong(string $secret): bool
    {
        $secret = trim($secret);

        if ($secret === '' || self::isPlaceholder($secret) || self::isPublishedFixtureSecret($secret)) {
            return false;
        }

        $normalized = self::normalized($secret);

        // Length floor, not an entropy measure: base64: secrets are counted after
        // decoding, everything else by raw string length (see the class docblock).
        return $normalized !== null && strlen($normalized) >= self::MINIMUM_SECRET_BYTES;
    }
}
