<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Illuminate\Contracts\Config\Repository;

/**
 * The single home for the non-trivial production security DECISIONS that both
 * the boot gate ({@see ProductionSecurityConfiguration}, which throws on the
 * first violation) and the read-only preflight ({@see \App\Application\Diagnostics\DeploymentDoctor},
 * which grades every invariant) must agree on. Each method is a pure predicate
 * over configuration values, so tightening a rule here updates both consumers
 * at once and they cannot drift on the decision itself. Presentation — terse
 * boot-gate exceptions versus the doctor's punch list — stays with each caller.
 */
final class SecurityInvariants
{
    /**
     * Database passwords published in this repository's compose and example
     * files. Reusing one in production is the exact footgun the boot gate must
     * catch. Consumed only by the production boot gate (never a runtime path),
     * so the local and test harnesses keep using them.
     *
     * @var list<string>
     */
    private const array PUBLISHED_DATABASE_PASSWORDS = [
        'app_local_password',
        'postgres',
        'postgres_test_password',
    ];

    /**
     * Mail drivers that accept mail and then drop it on the floor. Selecting one in
     * production silently discards invitation and password-reset emails, so it is as
     * unsafe as an unknown transport and both the boot gate and doctor must reject it.
     *
     * @var list<string>
     */
    private const array NON_DELIVERY_MAILERS = ['log', 'array', 'null'];

    /**
     * Configured bcrypt work factor, or null when it is present but not a
     * non-negative integer. The boot gate treats null as a hard failure; the
     * doctor falls back to the default. An absent key resolves to the default.
     */
    public static function configuredBcryptRounds(Repository $config): ?int
    {
        $rounds = $config->get('hashing.bcrypt.rounds', ProductionSecurityConfiguration::DEFAULT_BCRYPT_ROUNDS);

        if (is_int($rounds)) {
            return $rounds;
        }

        if (is_string($rounds) && preg_match('/^\d+$/', $rounds) === 1) {
            return (int) $rounds;
        }

        return null;
    }

    /**
     * Bcrypt cost embedded in a hash, or null when the value is not a bcrypt
     * hash. Used to prove the login-timing dummy hash matches the live rounds.
     */
    public static function bcryptHashCost(string $hash): ?int
    {
        $info = password_get_info($hash);
        $options = $info['options'] ?? null;
        $cost = is_array($options) ? ($options['cost'] ?? null) : null;

        if (($info['algoName'] ?? '') !== 'bcrypt' || !is_int($cost)) {
            return null;
        }

        return $cost;
    }

    public static function trustedProxiesAreConfigured(string $raw): bool
    {
        return trim($raw) !== '';
    }

    public static function trustedProxiesUseBroadDockerCidr(string $raw): bool
    {
        return str_contains(self::normalizedProxies($raw), '172.16.0.0/12');
    }

    public static function trustedProxiesUseWildcard(string $raw): bool
    {
        $normalized = self::normalizedProxies($raw);

        return $normalized === '*' || $normalized === '**';
    }

    /**
     * A CIDR with a zero-length prefix (0.0.0.0/0, ::/0) matches every address,
     * so trusting it is equivalent to the wildcard: any client could then spoof
     * X-Forwarded-For and defeat the IP-keyed rate limiters and audit trail.
     */
    public static function trustedProxiesUseAllAddressesCidr(string $raw): bool
    {
        return preg_match('#/0+(?:,|$)#', self::normalizedProxies($raw)) === 1;
    }

    /**
     * Whether the trusted-proxy list uses Symfony's REMOTE_ADDR sentinel, which
     * trusts whatever connects directly (the immediate peer) as the proxy. Safe
     * only when the app port is reachable exclusively through the edge; a directly
     * exposed app would let any client spoof X-Forwarded-For.
     */
    public static function trustedProxiesTrustImmediatePeer(string $raw): bool
    {
        return str_contains(self::normalizedProxies($raw), 'remote_addr');
    }

    public static function postgresSslModeIsVerifyFull(string $sslmode): bool
    {
        return strtolower(trim($sslmode)) === 'verify-full';
    }

    public static function postgresRootCertIsConfigured(string $rootcert): bool
    {
        return trim($rootcert) !== '';
    }

    /**
     * Artifact bytes must never be reachable beneath Laravel's public web root.
     * Resolve the nearest existing ancestor so symlinked directories are judged
     * by their physical target while a configured leaf that has not been created
     * yet is still checked lexically. Invalid or unresolvable paths fail closed.
     */
    public static function artifactStorageRootIsOutsidePublicPath(string $artifactRoot, string $publicPath): bool
    {
        $resolvedArtifactRoot = self::resolvedFilesystemPath($artifactRoot);
        $resolvedPublicPath = self::resolvedFilesystemPath($publicPath);

        if ($resolvedArtifactRoot === null || $resolvedPublicPath === null) {
            return false;
        }

        return $resolvedArtifactRoot !== $resolvedPublicPath
            && !str_starts_with($resolvedArtifactRoot, $resolvedPublicPath . DIRECTORY_SEPARATOR);
    }

    /**
     * Whether the cache store the rate limiters actually use shares counters across
     * requests AND production app replicas. Every limiter (login, 2FA challenge,
     * password reset, MCP, artifact previews, admin step-up) is weakened when its
     * counter disappears between requests or is isolated per replica. Laravel
     * resolves that store as `cache.limiter ?? cache.default` and reads its driver
     * from `cache.stores`, so validate the resolved DRIVER, not the store name.
     * Database, Redis, Memcached, and DynamoDB are shared backends; array/null do not
     * persist and file is node-local. Unknown/custom drivers fail closed because the
     * boot gate cannot prove that they coordinate replicas.
     *
     * @param array<array-key, mixed> $cacheStores
     */
    public static function cacheStoreSharesRateLimiting(
        string $limiterStore,
        string $defaultStore,
        array $cacheStores,
    ): bool {
        $store = trim($limiterStore) !== '' ? trim($limiterStore) : trim($defaultStore);

        if ($store === '') {
            return false;
        }

        $definition = $cacheStores[$store] ?? null;

        if (!is_array($definition)) {
            return false;
        }

        $driver = $definition['driver'] ?? null;
        $normalizedDriver = is_string($driver) ? strtolower(trim($driver)) : '';

        return in_array($normalizedDriver, ['database', 'redis', 'memcached', 'dynamodb'], true);
    }

    /**
     * Whether the artifact preview signing key collides with the application
     * key or any retired application key. A shared signing key would let anyone
     * who learns APP_KEY forge preview URLs, so it must be dedicated. Secrets
     * are already normalized to raw bytes by the caller.
     *
     * @param list<string> $previousApplicationSecrets
     */
    public static function signingKeyReusesApplicationKey(
        string $signingSecret,
        string $applicationSecret,
        array $previousApplicationSecrets,
    ): bool {
        if ($applicationSecret !== '' && hash_equals($applicationSecret, $signingSecret)) {
            return true;
        }

        foreach ($previousApplicationSecrets as $previousSecret) {
            if (hash_equals($previousSecret, $signingSecret)) {
                return true;
            }
        }

        return false;
    }

    public static function isSupportedDatabaseDriver(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    /**
     * Whether the configured mail transport will actually deliver in production. A
     * blank value, a known non-delivery driver (log/array/null), or a name that is not
     * a configured mailer (a typo like "stmp") all fail closed: the first two drop
     * mail silently, the last blows up only when the first email is sent.
     *
     * @param array<array-key, mixed> $configuredMailers the config('mail.mailers') map
     */
    public static function mailTransportIsDeliverable(string $mailer, array $configuredMailers): bool
    {
        $mailer = trim($mailer);

        if ($mailer === '' || in_array(strtolower($mailer), self::NON_DELIVERY_MAILERS, true)) {
            return false;
        }

        return array_key_exists($mailer, $configuredMailers);
    }

    /**
     * Whether the production database password is a real, non-placeholder value.
     * A shipped or empty password is a hard failure: verify-full TLS protects the
     * link, not a guessable credential reachable on the database port.
     */
    public static function databasePasswordIsAcceptable(string $password): bool
    {
        return trim($password) !== '' && !SecretStrength::isPlaceholder($password);
    }

    /**
     * Whether the value is a database password published in this repository's
     * source tree (compose defaults, .env examples). Consumed only by the
     * production boot gate, so the local/test harness keeps using these.
     */
    public static function databasePasswordIsPublishedFixture(string $password): bool
    {
        $candidate = trim($password);

        foreach (self::PUBLISHED_DATABASE_PASSWORDS as $fixture) {
            if (hash_equals($fixture, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a configured session cookie domain (SESSION_DOMAIN) is safe relative
     * to the artifact host. The session domain is routed through the single origin parser --
     * the same one that produced the already-canonical ASCII/punycode artifact host -- so the
     * two are compared in one spelling instead of byte-wise. A raw IDN/unicode SESSION_DOMAIN
     * a browser would IDNA-canonicalise (münchen.de -> xn--mnchen-3ya.de), or a percent /
     * backslash / non-canonical IPv4 spelling, otherwise slips past a str_ends_with coverage
     * test while the browser still scopes the app session cookie over the artifact origin.
     *
     *  - 'unset'   no shared cookie domain (host-only cookies) -- safe;
     *  - 'invalid' a domain is configured but is not a canonical ASCII host, so the browser
     *              would resolve it to a spelling this value never shows -> callers fail closed;
     *  - 'covers'  the canonical session domain equals or is a parent of the artifact host,
     *              so the app session cookie would be sent to it;
     *  - 'safe'    a valid session domain that does not cover the artifact host.
     *
     * @return 'unset'|'invalid'|'covers'|'safe'
     */
    public static function sessionCookieDomainCoverage(string $sessionDomain, string $artifactHost): string
    {
        if (self::strippedSessionDomain($sessionDomain) === '') {
            return 'unset';
        }

        $sessionHost = self::normalizedSessionCookieHost($sessionDomain);

        if ($sessionHost === null) {
            return 'invalid';
        }

        $normalizedArtifactHost = strtolower($artifactHost);

        return $normalizedArtifactHost === $sessionHost || str_ends_with($normalizedArtifactHost, '.' . $sessionHost)
            ? 'covers'
            : 'safe';
    }

    /**
     * The host a browser scopes a SESSION_DOMAIN cookie to, canonicalised through the single
     * origin parser (leading cookie-domain dot stripped). Null when unset or when the parser
     * rejects the spelling (non-ASCII/IDN, percent, backslash, or non-canonical IPv4).
     */
    public static function normalizedSessionCookieHost(string $sessionDomain): ?string
    {
        $candidate = self::strippedSessionDomain($sessionDomain);

        return $candidate === '' ? null : OriginNormalizer::tryHost($candidate);
    }

    private static function strippedSessionDomain(string $sessionDomain): string
    {
        return ltrim(trim($sessionDomain), '.');
    }

    private static function resolvedFilesystemPath(string $path): ?string
    {
        $candidate = rtrim(trim($path), DIRECTORY_SEPARATOR);

        if ($candidate === '' || str_contains($candidate, "\0")) {
            return null;
        }

        $unresolvedSegments = [];
        $resolved = realpath($candidate);

        while ($resolved === false) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return null;
            }

            array_unshift($unresolvedSegments, basename($candidate));
            $candidate = $parent;
            $resolved = realpath($candidate);
        }

        foreach ($unresolvedSegments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $resolved = dirname($resolved);
                continue;
            }

            $resolved = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private static function normalizedProxies(string $raw): string
    {
        return strtolower(str_replace(' ', '', $raw));
    }
}
