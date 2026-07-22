<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\InstallationLimitCeilings;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class ProductionSecurityConfiguration
{
    // The non-trivial invariant DECISIONS are defined once in SecurityInvariants
    // (and origins in OriginNormalizer); both this boot gate and the read-only
    // DeploymentDoctor preflight consume them, so a tightened rule updates both,
    // and DeploymentDoctorTest asserts the two stay in lockstep. Presentation --
    // throw-on-first here, graded punch list there -- stays with each consumer.
    public const int DEFAULT_BCRYPT_ROUNDS = 12;

    /**
     * @var list<string>
     */
    public const array ALLOWED_RUNTIME_ROLES = ['app', 'artifact-host', 'worker', 'scheduler'];

    public function __construct(
        private Repository $config,
    ) {
    }

    public function ensureSafe(): void
    {
        $this->ensureDebugDisabled();
        $this->ensureRuntimeRole();
        $applicationOrigin = $this->productionOrigin($this->string('app.url'));
        $artifactOrigin = $this->productionOrigin($this->string('app.artifact_url'));

        if ($applicationOrigin === $artifactOrigin) {
            throw new RuntimeException('Application and artifact origins must be different.');
        }

        // Distinct origins are not enough: cookies are scoped by host and ignore
        // the port (RFC 6265), so a host-only app session cookie set for the app
        // host would still be sent to an artifact origin that merely differs by
        // port or scheme. The two-origin isolation only holds if the hosts differ.
        if ($this->host($applicationOrigin) === $this->host($artifactOrigin)) {
            throw new RuntimeException('Application and artifact hosts must be different.');
        }

        $this->ensureApplicationKey();
        $this->ensureDedicatedSigningKey();
        $this->ensureArtifactReadLimitCanServeHtmlWrites();
        $this->ensureDatabaseDriver();
        $this->ensureDatabaseTls();
        $this->ensureDatabasePassword();
        $this->ensureDummyPasswordHashCost();
        $this->ensureSecureSessions();
        $this->ensureSessionDomainDoesNotCoverArtifactHost($artifactOrigin);
        $this->ensureTrustedProxies();
        $this->ensureArtifactFrameAncestors($applicationOrigin);
        $this->ensureReverbConfiguration($applicationOrigin);
        $this->ensureMailTransportIsDeliverable();
        $this->ensureTransactionalInvitationQueue();
        $this->ensureSharedRateLimiterCacheStore();

        if ($this->string('filesystems.disks.artifacts.visibility') !== 'private') {
            throw new RuntimeException('Artifact storage must be private.');
        }

        if (!SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
            $this->string('filesystems.disks.artifacts.root'),
            public_path(),
        )) {
            throw new RuntimeException('Artifact storage root must be outside the public web root.');
        }

        if ($this->string('app.bootstrap_admin_command') === '') {
            throw new RuntimeException('System Admin bootstrap path is required.');
        }

        if ($this->string('app.bootstrap_admin_password') !== '') {
            throw new RuntimeException('System Admin bootstrap password must not be configured persistently in production.');
        }

        if ($this->string('app.create_user_password') !== '') {
            throw new RuntimeException('User creation password fallback must not be configured persistently in production.');
        }

        if ($this->string('app.reset_user_password') !== '') {
            throw new RuntimeException('Password reset fallback must not be configured persistently in production.');
        }
    }

    private function ensureDebugDisabled(): void
    {
        if ($this->config->get('app.debug') === true) {
            throw new RuntimeException('Debug mode must be disabled in production.');
        }
    }

    private function ensureApplicationKey(): void
    {
        $this->validatedSecret(
            $this->string('app.key'),
            'Application key must be a non-placeholder 32-byte secret.',
        );
    }

    private function ensureRuntimeRole(): void
    {
        if (!in_array($this->string('app.runtime_role'), self::ALLOWED_RUNTIME_ROLES, true)) {
            throw new RuntimeException('Runtime role must be one of app, artifact-host, worker, or scheduler.');
        }
    }

    private function ensureDedicatedSigningKey(): void
    {
        $signingKey = $this->string('app.artifact_url_signing_key');

        if ($signingKey === '') {
            throw new RuntimeException('Artifact preview signing key is required.');
        }

        if (SecretStrength::isPublishedSigningKeyFixture($signingKey)) {
            throw new RuntimeException('Artifact preview signing key must not be a published test key.');
        }

        $signingKeySecret = $this->validatedSecret(
            $signingKey,
            'Artifact preview signing key must be a non-placeholder 32-byte secret.',
        );
        $applicationKey = $this->string('app.key');
        $applicationSecret = $applicationKey === '' ? '' : $this->validatedSecret(
            $applicationKey,
            'Application key must be a non-placeholder 32-byte secret.',
        );

        if (SecurityInvariants::signingKeyReusesApplicationKey(
            $signingKeySecret,
            $applicationSecret,
            $this->previousApplicationKeys(),
        )) {
            throw new RuntimeException('Artifact preview signing key must be dedicated.');
        }
    }

    private function ensureArtifactReadLimitCanServeHtmlWrites(): void
    {
        if ($this->positiveInt('pages.max_html_bytes') > InstallationLimitCeilings::CONTENT_BYTES) {
            throw new RuntimeException('HTML write limit must not exceed the production HTTP request envelope.');
        }

        if ($this->positiveInt('pages.max_markdown_bytes') > InstallationLimitCeilings::CONTENT_BYTES) {
            throw new RuntimeException('Markdown write limit must not exceed the HTTP request envelope.');
        }

        if ($this->positiveInt('pages.artifact_max_bytes') < max(
            $this->positiveInt('pages.max_html_bytes'),
            $this->positiveInt('pages.max_markdown_bytes'),
        )) {
            throw new RuntimeException('Artifact read limit must be greater than or equal to every content write limit.');
        }
    }

    private function ensureDummyPasswordHashCost(): void
    {
        $dummyHash = $this->string('auth.dummy_password_hash');

        if ($dummyHash === '') {
            throw new RuntimeException('Dummy password hash is required in production.');
        }

        $hashCost = SecurityInvariants::bcryptHashCost($dummyHash);

        if ($hashCost === null) {
            throw new RuntimeException('Dummy password hash must be a valid bcrypt hash.');
        }

        if ($hashCost !== $this->configuredBcryptRounds()) {
            throw new RuntimeException('Dummy password hash bcrypt cost must match configured bcrypt rounds.');
        }
    }

    private function ensureSecureSessions(): void
    {
        if ($this->string('session.driver') !== 'database') {
            throw new RuntimeException('Session driver must be database in production.');
        }

        if ($this->config->get('session.secure') !== true) {
            throw new RuntimeException('Session cookies must be secure in production.');
        }

        if ($this->config->get('session.encrypt') !== true) {
            throw new RuntimeException('Session payloads must be encrypted in production.');
        }

        if ($this->config->get('session.http_only') !== true) {
            throw new RuntimeException('Session cookies must be HTTP-only in production.');
        }

        if (!in_array(strtolower($this->string('session.same_site')), ['lax', 'strict'], true)) {
            throw new RuntimeException('Session cookies must use lax or strict SameSite in production.');
        }
    }

    private function ensureSessionDomainDoesNotCoverArtifactHost(string $artifactOrigin): void
    {
        $artifactHost = parse_url($artifactOrigin, PHP_URL_HOST);

        if (!is_string($artifactHost) || $artifactHost === '') {
            throw new RuntimeException('Configured production origin is invalid.');
        }

        // Route SESSION_DOMAIN through the single origin parser rather than a raw
        // strtolower/ltrim, so a non-ASCII/IDN spelling a browser would IDNA-canonicalise
        // cannot slip past the coverage test and scope the app cookie over the artifact host.
        $coverage = SecurityInvariants::sessionCookieDomainCoverage($this->string('session.domain'), $artifactHost);

        if ($coverage === 'invalid') {
            throw new RuntimeException('Session domain must be a canonical ASCII host.');
        }

        if ($coverage === 'covers') {
            throw new RuntimeException('Session domain must not cover the artifact origin.');
        }
    }

    private function ensureTrustedProxies(): void
    {
        $trustedProxies = $this->string('trustedproxy.raw');

        if (!SecurityInvariants::trustedProxiesAreConfigured($trustedProxies)) {
            throw new RuntimeException('Trusted proxies must be explicitly configured for production.');
        }

        if (SecurityInvariants::trustedProxiesUseBroadDockerCidr($trustedProxies)) {
            throw new RuntimeException('Trusted proxies must not use the broad development Docker CIDR in production.');
        }

        if (SecurityInvariants::trustedProxiesUseWildcard($trustedProxies)) {
            throw new RuntimeException('Trusted proxies must not use a wildcard in production.');
        }

        if (SecurityInvariants::trustedProxiesUseAllAddressesCidr($trustedProxies)) {
            throw new RuntimeException('Trusted proxies must not use an all-addresses CIDR (for example 0.0.0.0/0 or ::/0) in production.');
        }
    }

    private function ensureArtifactFrameAncestors(string $applicationOrigin): void
    {
        $configured = preg_split(
            '/[\s,]+/',
            $this->string('app.artifact_frame_ancestors'),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if (!is_array($configured) || count($configured) !== 1) {
            throw new RuntimeException('Artifact frame ancestors must include only the application origin.');
        }

        try {
            $configuredOrigin = $this->origin($configured[0]);
        } catch (RuntimeException) {
            throw new RuntimeException('Artifact frame ancestors must include only the application origin.');
        }

        if ($configuredOrigin !== $applicationOrigin) {
            throw new RuntimeException('Artifact frame ancestors must include only the application origin.');
        }
    }

    private function ensureReverbConfiguration(string $applicationOrigin): void
    {
        if ($this->string('broadcasting.default') !== 'reverb') {
            return;
        }

        $this->validatedSecret(
            $this->string('broadcasting.connections.reverb.secret'),
            'Reverb app secret must be a non-placeholder 32-byte secret.',
        );

        try {
            $reverbOrigin = $this->productionOrigin($this->string('app.reverb_url'));
        } catch (RuntimeException) {
            throw new RuntimeException('Reverb public origin must match the application origin.');
        }

        if ($reverbOrigin !== $applicationOrigin) {
            throw new RuntimeException('Reverb public origin must match the application origin.');
        }

        $allowedOrigins = $this->config->get('reverb.apps.apps.0.allowed_origins');

        if (!is_array($allowedOrigins) || count($allowedOrigins) !== 1) {
            throw new RuntimeException('Reverb allowed origins must include only the application origin.');
        }

        $allowedOrigin = $allowedOrigins[0] ?? null;

        if (!is_string($allowedOrigin) || trim($allowedOrigin) === '*') {
            throw new RuntimeException('Reverb allowed origins must include only the application origin.');
        }

        $configuredAllowedOrigins = $this->config->get('reverb.apps.apps.0.configured_allowed_origins', $allowedOrigins);

        if (!is_array($configuredAllowedOrigins) || count($configuredAllowedOrigins) !== 1) {
            throw new RuntimeException('Reverb allowed origins must include only the application origin.');
        }

        $configuredAllowedOrigin = $configuredAllowedOrigins[0] ?? null;

        if (!is_string($configuredAllowedOrigin) || trim($configuredAllowedOrigin) === '*') {
            throw new RuntimeException('Reverb allowed origins must include only the application origin.');
        }

        try {
            if (str_contains($configuredAllowedOrigin, '://')) {
                $normalizedConfiguredOrigin = $this->productionOrigin($configuredAllowedOrigin);

                if ($normalizedConfiguredOrigin !== $applicationOrigin) {
                    throw new RuntimeException('Reverb allowed origins must include only the application origin.');
                }
            }

            $applicationHost = $this->host($applicationOrigin);

            if ($this->host($allowedOrigin) !== $applicationHost) {
                throw new RuntimeException('Reverb allowed origins must include only the application origin.');
            }

            if ($this->host($configuredAllowedOrigin) !== $applicationHost) {
                throw new RuntimeException('Reverb allowed origins must include only the application origin.');
            }
        } catch (RuntimeException) {
            throw new RuntimeException('Reverb allowed origins must include only the application origin.');
        }

        if ($this->config->get('reverb.apps.apps.0.rate_limiting.enabled') !== true) {
            throw new RuntimeException('Reverb client-event rate limiting must be enabled in production.');
        }

        $this->ensureReverbMaxConnectionsBounded();
    }

    private function ensureDatabaseDriver(): void
    {
        if (!SecurityInvariants::isSupportedDatabaseDriver($this->string('database.default'))) {
            throw new RuntimeException('Database connection must be PostgreSQL (pgsql) in production.');
        }
    }

    private function ensureDatabasePassword(): void
    {
        $password = $this->string('database.connections.pgsql.password');

        if (!SecurityInvariants::databasePasswordIsAcceptable($password)) {
            throw new RuntimeException('Database password must be a real, non-placeholder secret in production.');
        }

        if (SecurityInvariants::databasePasswordIsPublishedFixture($password)) {
            throw new RuntimeException('Database password must not be a published development default in production.');
        }
    }

    private function ensureSharedRateLimiterCacheStore(): void
    {
        if (!SecurityInvariants::cacheStoreSharesRateLimiting(
            $this->string('cache.limiter'),
            $this->string('cache.default'),
            $this->cacheStores(),
        )) {
            throw new RuntimeException(
                'Cache store must share rate-limit counters across production app replicas. The rate limiter store (cache.limiter or cache.default) must resolve to a defined database, Redis, Memcached, or DynamoDB cache driver.',
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function cacheStores(): array
    {
        $stores = $this->config->get('cache.stores');

        return is_array($stores) ? $stores : [];
    }

    private function ensureMailTransportIsDeliverable(): void
    {
        if (!SecurityInvariants::mailTransportIsDeliverable(
            $this->string('mail.default'),
            $this->configuredMailers(),
            $this->string('services.resend.key'),
        )) {
            throw new RuntimeException(
                'Mail transport must be a real, deliverable transport in production. The log and array drivers discard invitation and password-reset emails, and an unknown mailer fails only when the first message is sent.',
            );
        }
    }

    private function ensureTransactionalInvitationQueue(): void
    {
        $queue = $this->string('queue.default');

        if (!SecurityInvariants::invitationQueueIsTransactional(
            driver: $this->string(sprintf('queue.connections.%s.driver', $queue)),
            queueDatabaseConnection: $this->string(sprintf('queue.connections.%s.connection', $queue)),
            primaryDatabaseConnection: $this->string('database.default'),
            dispatchesAfterCommit: $this->config->get(
                sprintf('queue.connections.%s.after_commit', $queue),
            ) !== false,
        )) {
            throw new RuntimeException(
                'Invitation delivery queue must use the primary database connection with after-commit dispatch disabled in production.',
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configuredMailers(): array
    {
        $mailers = $this->config->get('mail.mailers');

        return is_array($mailers) ? $mailers : [];
    }

    private function ensureDatabaseTls(): void
    {
        if ($this->string('database.default') !== 'pgsql') {
            return;
        }

        if (!SecurityInvariants::postgresSslModeIsVerifyFull($this->string('database.connections.pgsql.sslmode'))) {
            throw new RuntimeException('PostgreSQL sslmode must be verify-full in production.');
        }

        $rootCertificate = $this->string('database.connections.pgsql.sslrootcert');

        if ($rootCertificate === '') {
            throw new RuntimeException('PostgreSQL verify-full requires an explicit root certificate in production.');
        }

        if (!SecurityInvariants::postgresRootCertIsReadable($rootCertificate)) {
            throw new RuntimeException('PostgreSQL verify-full requires a readable root certificate file in production.');
        }
    }

    private function ensureReverbMaxConnectionsBounded(): void
    {
        $value = $this->config->get('reverb.apps.apps.0.max_connections');

        if (is_int($value)) {
            $limit = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $limit = (int) $value;
        } else {
            throw new RuntimeException('Reverb max connections must be bounded in production.');
        }

        if ($limit < 1) {
            throw new RuntimeException('Reverb max connections must be bounded in production.');
        }
    }

    private function productionOrigin(string $url): string
    {
        $origin = $this->origin($url);

        if (!str_starts_with($origin, 'https://')) {
            throw new RuntimeException('Configured production origins must use HTTPS.');
        }

        return $origin;
    }

    private function origin(string $url): string
    {
        // Production origins must be pure origins -- reject userinfo, a non-root path, query,
        // fragment, backslash, or percent-escape that PHP would keep but a browser resolves
        // differently, rather than silently drop them like tryParse() does.
        $origin = OriginNormalizer::tryParsePureOrigin($url);

        if ($origin === null) {
            throw new RuntimeException('Configured production origin is invalid.');
        }

        return $origin->canonical();
    }

    private function host(string $urlOrHost): string
    {
        $host = OriginNormalizer::tryHost($urlOrHost);

        if ($host === null) {
            throw new RuntimeException('Configured production origin is invalid.');
        }

        return $host;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private function previousApplicationKeys(): array
    {
        $keys = $this->config->get('app.previous_keys', []);

        if (!is_array($keys)) {
            return [];
        }

        $validatedKeys = [];

        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $validatedKeys[] = $this->validatedSecret(
                trim($key),
                'Application key must be a non-placeholder 32-byte secret.',
            );
        }

        return $validatedKeys;
    }

    private function positiveInt(string $key): int
    {
        $value = $this->config->get($key);

        if (is_int($value)) {
            $limit = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $limit = (int) $value;
        } else {
            throw new RuntimeException(sprintf('Production limit [%s] must be a positive integer.', $key));
        }

        if ($limit < 1) {
            throw new RuntimeException(sprintf('Production limit [%s] must be a positive integer.', $key));
        }

        return $limit;
    }

    private function configuredBcryptRounds(): int
    {
        $rounds = SecurityInvariants::configuredBcryptRounds($this->config);

        if ($rounds === null) {
            throw new RuntimeException('Configured bcrypt rounds must be an integer.');
        }

        return $rounds;
    }

    private function validatedSecret(string $secret, string $message): string
    {
        if (!SecretStrength::isStrong($secret)) {
            throw new RuntimeException($message);
        }

        $normalized = SecretStrength::normalized($secret);

        if ($normalized === null) {
            throw new RuntimeException($message);
        }

        return $normalized;
    }
}
