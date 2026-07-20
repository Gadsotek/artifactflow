<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\ProductionSecurityConfiguration;
use App\Infrastructure\Security\SecretStrength;
use App\Infrastructure\Security\SecurityInvariants;
use Illuminate\Contracts\Config\Repository;

/**
 * Read-only preflight that mirrors the invariants ProductionSecurityConfiguration
 * enforces at boot, reported as a full pass/fail punch list instead of throwing on
 * the first violation. It never writes. Universal invariants are graded in every
 * environment; production-hardening invariants are graded as failures only in
 * production and reported as skipped (with the current value) locally, so the same
 * command is useful both before `make up-local` and before a production deploy.
 */
final readonly class DeploymentDoctor
{
    public function __construct(
        private Repository $config,
    ) {
    }

    public function run(): DoctorReport
    {
        $production = $this->isProduction();
        $checks = [
            $this->runtimeRoleCheck(),
            $this->originsDistinctCheck($production),
            $this->originsPureCheck($production),
            $this->hostPortsCheck($production),
            $this->applicationKeyCheck(),
            $this->dedicatedSigningKeyCheck(),
            $this->artifactFrameAncestorsCheck(),
            $this->artifactReadLimitCheck(),
            $this->httpsOriginsCheck($production),
            $this->databaseDriverCheck($production),
            $this->databaseTlsCheck($production),
            $this->databasePasswordCheck($production),
            $this->mailTransportCheck($production),
            $this->secureSessionCheck($production),
            $this->trustedProxiesCheck($production),
            $this->artifactStoragePrivateCheck($production),
            $this->cacheStoreCheck($production),
            $this->noPersistentAdminPasswordCheck($production),
            $this->debugDisabledCheck($production),
            $this->dummyPasswordHashCheck($production),
            $this->sessionDomainCheck($production),
            $this->reverbCheck($production),
            $this->bootstrapCommandCheck($production),
        ];

        return new DoctorReport($production, $checks);
    }

    private function cacheStoreCheck(bool $production): DoctorCheck
    {
        $limiterStore = $this->string('cache.limiter');
        $defaultStore = $this->string('cache.default');
        $store = $limiterStore !== '' ? $limiterStore : $defaultStore;
        $label = $store === '' ? '(unset)' : $store;

        if (!$production) {
            return $this->skipped(
                'cache_store',
                'Cache store',
                sprintf("Rate limiter cache store is '%s'; production requires a shared store so every app replica enforces the same counters.", $label),
            );
        }

        if (!SecurityInvariants::cacheStoreSharesRateLimiting($limiterStore, $defaultStore, $this->cacheStores())) {
            return $this->fail(
                'cache_store',
                'Cache store',
                sprintf(
                    "Rate limiter cache store '%s' does not provide shared counters; point CACHE_STORE (or cache.limiter) at a defined database, Redis, Memcached, or DynamoDB store so login, 2FA, and MCP limits hold across replicas.",
                    $label,
                ),
            );
        }

        return $this->pass('cache_store', 'Cache store', sprintf("Rate limiter cache store is '%s'.", $label));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function cacheStores(): array
    {
        $stores = $this->config->get('cache.stores');

        return is_array($stores) ? $stores : [];
    }

    private function mailTransportCheck(bool $production): DoctorCheck
    {
        $mailer = $this->string('mail.default');
        $label = $mailer === '' ? '(unset)' : $mailer;

        if (!$production) {
            return $this->skipped(
                'mail_transport',
                'Mail transport',
                sprintf("Mailer is '%s'; production requires a real, deliverable transport.", $label),
            );
        }

        if (!SecurityInvariants::mailTransportIsDeliverable($mailer, $this->configuredMailers())) {
            return $this->fail(
                'mail_transport',
                'Mail transport',
                sprintf(
                    "Mailer '%s' will not deliver in production; set MAIL_MAILER to a real, configured transport (smtp/resend). The log and array drivers drop invitation and reset emails, and an unknown name fails only at send time.",
                    $label,
                ),
            );
        }

        return $this->pass('mail_transport', 'Mail transport', sprintf("Mailer is '%s'.", $label));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configuredMailers(): array
    {
        $mailers = $this->config->get('mail.mailers');

        return is_array($mailers) ? $mailers : [];
    }

    private function debugDisabledCheck(bool $production): DoctorCheck
    {
        $debugEnabled = $this->config->get('app.debug') === true;

        if (!$production) {
            return $this->skipped('debug_disabled', 'Debug mode', $debugEnabled ? 'Debug is on; fine locally, forbidden in production.' : 'Debug is off.');
        }

        return $debugEnabled
            ? $this->fail('debug_disabled', 'Debug mode', 'Set APP_DEBUG=false in production.')
            : $this->pass('debug_disabled', 'Debug mode', 'Debug is disabled.');
    }

    private function dummyPasswordHashCheck(bool $production): DoctorCheck
    {
        if (!$production) {
            return $this->skipped('dummy_password_hash', 'Login-timing dummy hash', 'Local bcrypt rounds vary; production requires a bcrypt dummy hash matching the configured rounds.');
        }

        $dummyHash = $this->string('auth.dummy_password_hash');

        if ($dummyHash === '') {
            return $this->fail('dummy_password_hash', 'Login-timing dummy hash', 'Set AUTH_DUMMY_PASSWORD_HASH (or keep the shipped default) in production.');
        }

        $hashCost = SecurityInvariants::bcryptHashCost($dummyHash);

        if ($hashCost === null) {
            return $this->fail('dummy_password_hash', 'Login-timing dummy hash', 'The dummy password hash must be a valid bcrypt hash.');
        }

        // The boot gate aborts when BCRYPT_ROUNDS is present but not an integer.
        // Grade the same failure here instead of silently falling back to the
        // default rounds and blessing a hash-cost match the boot gate never reaches.
        if (SecurityInvariants::configuredBcryptRounds($this->config) === null) {
            return $this->fail('dummy_password_hash', 'Login-timing dummy hash', 'BCRYPT_ROUNDS must be an integer so the dummy hash cost can be matched.');
        }

        if ($hashCost !== $this->configuredBcryptRounds()) {
            return $this->fail('dummy_password_hash', 'Login-timing dummy hash', 'The dummy hash bcrypt cost must match BCRYPT_ROUNDS so login timing stays uniform.');
        }

        return $this->pass('dummy_password_hash', 'Login-timing dummy hash', 'Bcrypt dummy hash matches the configured rounds.');
    }

    private function sessionDomainCheck(bool $production): DoctorCheck
    {
        if (!$production) {
            return $this->skipped('session_domain', 'Session cookie domain', 'Production must not scope the session cookie over the artifact host.');
        }

        $artifactOrigin = $this->origin($this->string('app.artifact_url'));
        $artifactHost = $artifactOrigin === null ? null : parse_url($artifactOrigin, PHP_URL_HOST);

        if (!is_string($artifactHost) || $artifactHost === '') {
            return $this->fail('session_domain', 'Session cookie domain', 'ARTIFACT_URL must be a valid origin to grade the session domain.');
        }

        $sessionDomain = $this->string('session.domain');

        // Same single-parser decision the boot gate enforces, so the doctor cannot bless a
        // non-ASCII SESSION_DOMAIN the browser would canonicalise to cover the artifact host.
        return match (SecurityInvariants::sessionCookieDomainCoverage($sessionDomain, $artifactHost)) {
            'unset' => $this->pass('session_domain', 'Session cookie domain', 'No shared session cookie domain is configured.'),
            'invalid' => $this->fail('session_domain', 'Session cookie domain', 'SESSION_DOMAIN must be a canonical ASCII host; a raw IDN/unicode spelling is IDNA-canonicalised by browsers and can cover the artifact host unseen.'),
            'covers' => $this->fail('session_domain', 'Session cookie domain', 'SESSION_DOMAIN must not cover the artifact origin, or app cookies leak onto the artifact host.'),
            'safe' => $this->pass('session_domain', 'Session cookie domain', sprintf('Session domain %s does not cover the artifact host.', (string) SecurityInvariants::normalizedSessionCookieHost($sessionDomain))),
        };
    }

    private function reverbCheck(bool $production): DoctorCheck
    {
        if ($this->string('broadcasting.default') !== 'reverb') {
            return $this->skipped('reverb', 'Reverb realtime', 'Reverb is not the active broadcast driver.');
        }

        if (!$production) {
            return $this->skipped('reverb', 'Reverb realtime', 'Local Reverb defaults apply; production requires a strong secret, matching origins, and rate limiting.');
        }

        if (!SecretStrength::isStrong($this->string('broadcasting.connections.reverb.secret'))) {
            return $this->fail('reverb', 'Reverb realtime', 'Reverb app secret must be a strong, non-placeholder 32-byte secret.');
        }

        $applicationOrigin = $this->origin($this->string('app.url'));
        $reverbOrigin = $this->origin($this->string('app.reverb_url'));

        if ($applicationOrigin === null || $reverbOrigin === null || $reverbOrigin !== $applicationOrigin) {
            return $this->fail('reverb', 'Reverb realtime', 'REVERB public origin must match the application origin.');
        }

        $applicationHost = parse_url($applicationOrigin, PHP_URL_HOST);

        foreach (['reverb.apps.apps.0.allowed_origins', 'reverb.apps.apps.0.configured_allowed_origins'] as $key) {
            $allowedOrigins = $this->config->get($key, $key === 'reverb.apps.apps.0.allowed_origins' ? null : $this->config->get('reverb.apps.apps.0.allowed_origins'));

            if (!is_array($allowedOrigins) || count($allowedOrigins) !== 1) {
                return $this->fail('reverb', 'Reverb realtime', 'Reverb allowed origins must include only the application origin.');
            }

            $allowedOrigin = $allowedOrigins[0] ?? null;

            if (!is_string($allowedOrigin) || trim($allowedOrigin) === '*' || $this->hostOf($allowedOrigin) !== $applicationHost) {
                return $this->fail('reverb', 'Reverb realtime', 'Reverb allowed origins must include only the application origin.');
            }

            // The operator-facing configured_allowed_origins may carry a scheme. When
            // it does, it must match the application origin in full (scheme and port),
            // not just the host -- otherwise an http:// entry against an https app
            // origin passes the host check here while the boot gate rejects it.
            if ($key === 'reverb.apps.apps.0.configured_allowed_origins'
                && str_contains($allowedOrigin, '://')
                && $this->origin($allowedOrigin) !== $applicationOrigin) {
                return $this->fail('reverb', 'Reverb realtime', 'Reverb allowed origins must include only the application origin.');
            }
        }

        if ($this->config->get('reverb.apps.apps.0.rate_limiting.enabled') !== true) {
            return $this->fail('reverb', 'Reverb realtime', 'Reverb client-event rate limiting must be enabled in production.');
        }

        if ($this->positiveInt('reverb.apps.apps.0.max_connections') === null) {
            return $this->fail('reverb', 'Reverb realtime', 'Reverb max connections must be bounded in production.');
        }

        return $this->pass('reverb', 'Reverb realtime', 'Reverb secret, origins, rate limiting, and connection bound are hardened.');
    }

    private function bootstrapCommandCheck(bool $production): DoctorCheck
    {
        $configured = $this->string('app.bootstrap_admin_command') !== '';

        if ($configured) {
            return $this->pass('bootstrap_command', 'Admin bootstrap path', 'A System Admin bootstrap command is configured.');
        }

        if (!$production) {
            return $this->skipped('bootstrap_command', 'Admin bootstrap path', 'Production requires a configured System Admin bootstrap command.');
        }

        return $this->fail('bootstrap_command', 'Admin bootstrap path', 'Configure the System Admin bootstrap command for production.');
    }

    private function runtimeRoleCheck(): DoctorCheck
    {
        $role = $this->string('app.runtime_role');

        if (in_array($role, ProductionSecurityConfiguration::ALLOWED_RUNTIME_ROLES, true)) {
            return $this->pass('runtime_role', 'Runtime role', sprintf('Role is %s.', $role));
        }

        return $this->fail('runtime_role', 'Runtime role', 'Set APP_RUNTIME_ROLE to app, artifact-host, worker, or scheduler.');
    }

    private function originsDistinctCheck(bool $production): DoctorCheck
    {
        $appOrigin = $this->origin($this->string('app.url'));
        $artifactOrigin = $this->origin($this->string('app.artifact_url'));

        if ($appOrigin === null || $artifactOrigin === null) {
            return $this->fail('origins_distinct', 'App and artifact origins', 'Set valid APP_URL and ARTIFACT_URL.');
        }

        if ($appOrigin === $artifactOrigin) {
            return $this->fail(
                'origins_distinct',
                'App and artifact origins',
                'APP_URL and ARTIFACT_URL must be different origins so untrusted artifacts never share the app origin.',
            );
        }

        // Cookies ignore the port (RFC 6265), and ports do not make requests
        // cross-site for SameSite processing, so a shared host sends the app
        // session cookie to the cookieless artifact origin in every environment,
        // not just production. The shipped local/e2e defaults therefore pair
        // localhost with 127.0.0.1, and the doctor must never bless a shared
        // host anywhere: this fails in local and test setups exactly like the
        // production boot gate does.
        $appHost = $this->hostOf($appOrigin);
        $artifactHost = $this->hostOf($artifactOrigin);

        if ($appHost !== null && $appHost === $artifactHost) {
            return $this->fail(
                'origins_distinct',
                'App and artifact origins',
                'APP_URL and ARTIFACT_URL must use different hosts; cookies ignore the port, so a shared host leaks the app session cookie onto the artifact origin. Pair localhost with 127.0.0.1 (the shipped defaults) to keep the hosts apart locally.',
            );
        }

        return $this->pass('origins_distinct', 'App and artifact origins', sprintf('%s vs %s.', $appOrigin, $artifactOrigin));
    }

    /**
     * Docker publishes host ports (APP_PORT, ARTIFACT_HOST_PORT) independently of
     * the ports embedded in APP_URL / ARTIFACT_URL, so changing one without the
     * other yields an app that boots on one port while generating links to
     * another. Compose passes the mappings in so this can be graded; it is a
     * local usability aid, never a boot-gate invariant, hence Warn at worst.
     */
    private function hostPortsCheck(bool $production): DoctorCheck
    {
        if ($production) {
            return $this->skipped('host_ports', 'Docker host ports', 'Host-port mappings are a local development aid; production origins come from your TLS edge.');
        }

        $pairs = [
            ['APP_PORT', 'app.host_port', 'APP_URL', 'app.url'],
            ['ARTIFACT_HOST_PORT', 'app.artifact_host_port', 'ARTIFACT_URL', 'app.artifact_url'],
        ];

        $matches = [];
        $problems = [];

        foreach ($pairs as [$portName, $portKey, $urlName, $urlKey]) {
            if ($this->string($portKey) === '') {
                continue;
            }

            $port = $this->positiveInt($portKey);

            if ($port === null || $port > 65535) {
                $problems[] = sprintf('%s is not a valid port.', $portName);

                continue;
            }

            $origin = $this->origin($this->string($urlKey));
            $urlPort = $origin === null ? null : parse_url($origin, PHP_URL_PORT);

            if (!is_int($urlPort)) {
                $problems[] = sprintf('%s must be a valid origin to grade %s.', $urlName, $portName);

                continue;
            }

            if ($urlPort !== $port) {
                $problems[] = sprintf(
                    'Docker publishes host port %d (%s) but %s points at port %d; change them together, or ignore this if a proxy fronts the origin.',
                    $port,
                    $portName,
                    $urlName,
                    $urlPort,
                );

                continue;
            }

            $matches[] = sprintf('%s matches %s (%d)', $portName, $urlName, $port);
        }

        if ($problems !== []) {
            return $this->warn('host_ports', 'Docker host ports', implode(' ', $problems));
        }

        if ($matches === []) {
            return $this->skipped('host_ports', 'Docker host ports', 'No Docker host-port mapping is visible to the container.');
        }

        return $this->pass('host_ports', 'Docker host ports', implode('; ', $matches) . '.');
    }

    private function applicationKeyCheck(): DoctorCheck
    {
        if ($this->isValidSecret($this->string('app.key'))) {
            return $this->pass('app_key', 'Application key', 'APP_KEY is a strong, non-placeholder secret.');
        }

        return $this->fail('app_key', 'Application key', 'Generate a strong APP_KEY (php artisan key:generate).');
    }

    private function dedicatedSigningKeyCheck(): DoctorCheck
    {
        $signingKey = $this->string('app.artifact_url_signing_key');

        if (!$this->isValidSecret($signingKey)) {
            return $this->fail(
                'signing_key',
                'Artifact signing key',
                'Set a strong ARTIFACT_URL_SIGNING_KEY (php artisan artifactflow does this on install).',
            );
        }

        // isStrong() blesses the repository's published e2e signing key (a valid
        // 32-byte string), but the boot gate rejects it outright. Grade it as a
        // failure too rather than pass on a key the production boot aborts on.
        if (SecretStrength::isPublishedSigningKeyFixture($signingKey)) {
            return $this->fail(
                'signing_key',
                'Artifact signing key',
                'ARTIFACT_URL_SIGNING_KEY must not be a repository-published test key.',
            );
        }

        $appSecret = $this->normalizedSecret($this->string('app.key'));
        $signingSecret = $this->normalizedSecret($signingKey);

        if ($signingSecret !== null && SecurityInvariants::signingKeyReusesApplicationKey(
            $signingSecret,
            $appSecret ?? '',
            $this->previousApplicationSecrets(),
        )) {
            return $this->fail(
                'signing_key',
                'Artifact signing key',
                'ARTIFACT_URL_SIGNING_KEY must be dedicated and different from APP_KEY.',
            );
        }

        return $this->pass('signing_key', 'Artifact signing key', 'Signing key is present, strong, and dedicated.');
    }

    /**
     * Retired application keys, normalized to raw bytes. A signing key that
     * matches any of these is as compromised as one matching the live APP_KEY,
     * so the preflight grades them together with the boot gate.
     *
     * @return list<string>
     */
    private function previousApplicationSecrets(): array
    {
        $keys = $this->config->get('app.previous_keys', []);

        if (!is_array($keys)) {
            return [];
        }

        $secrets = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            $normalized = $this->normalizedSecret(trim($key));

            if ($normalized !== null && $normalized !== '') {
                $secrets[] = $normalized;
            }
        }

        return $secrets;
    }

    private function artifactFrameAncestorsCheck(): DoctorCheck
    {
        $appOrigin = $this->origin($this->string('app.url'));
        $configured = preg_split('/[\s,]+/', $this->string('app.artifact_frame_ancestors'), -1, PREG_SPLIT_NO_EMPTY);
        $configured = is_array($configured) ? $configured : [];

        if ($appOrigin !== null && count($configured) === 1 && $this->origin($configured[0]) === $appOrigin) {
            return $this->pass('frame_ancestors', 'Artifact frame ancestors', 'Only the app origin may embed artifacts.');
        }

        return $this->fail(
            'frame_ancestors',
            'Artifact frame ancestors',
            'ARTIFACT_FRAME_ANCESTORS must list only the application origin.',
        );
    }

    private function artifactReadLimitCheck(): DoctorCheck
    {
        $read = $this->positiveInt('pages.artifact_max_bytes');
        $write = $this->positiveInt('pages.max_html_bytes');

        if ($read === null || $write === null) {
            return $this->fail('artifact_limits', 'Artifact size limits', 'Artifact and HTML byte limits must be positive integers.');
        }

        if ($read < $write) {
            return $this->fail(
                'artifact_limits',
                'Artifact size limits',
                'Artifact read limit must be greater than or equal to the HTML write limit.',
            );
        }

        return $this->pass('artifact_limits', 'Artifact size limits', sprintf('Read %d >= write %d bytes.', $read, $write));
    }

    private function httpsOriginsCheck(bool $production): DoctorCheck
    {
        $appOrigin = $this->origin($this->string('app.url'));
        $artifactOrigin = $this->origin($this->string('app.artifact_url'));
        $bothHttps = $appOrigin !== null
            && $artifactOrigin !== null
            && str_starts_with($appOrigin, 'https://')
            && str_starts_with($artifactOrigin, 'https://');

        if (!$production) {
            return $this->skipped('https_origins', 'HTTPS origins', $bothHttps ? 'Both origins use HTTPS.' : 'Plain HTTP is fine locally; production requires HTTPS.');
        }

        return $bothHttps
            ? $this->pass('https_origins', 'HTTPS origins', 'Both origins use HTTPS.')
            : $this->fail('https_origins', 'HTTPS origins', 'APP_URL and ARTIFACT_URL must use HTTPS in production.');
    }

    /**
     * Production origins must be pure origins. tryParse() (used by the checks above) silently
     * drops a userinfo, non-root path, query, or fragment and keeps only scheme://host:port,
     * but a browser resolves several of those differently, so the boot gate rejects any
     * impure production origin. Grade the same invariant here so the doctor cannot bless a
     * config the next production boot would refuse.
     */
    private function originsPureCheck(bool $production): DoctorCheck
    {
        if (!$production) {
            return $this->skipped('origins_pure', 'Production origin form', 'Origin purity is enforced only for production origins.');
        }

        foreach (['APP_URL' => 'app.url', 'ARTIFACT_URL' => 'app.artifact_url'] as $label => $key) {
            if (OriginNormalizer::tryParsePureOrigin($this->string($key)) === null) {
                return $this->fail(
                    'origins_pure',
                    'Production origin form',
                    sprintf(
                        '%s must be a bare origin (scheme://host[:port]) with no userinfo, path, query, fragment, backslash, or percent-escape.',
                        $label,
                    ),
                );
            }
        }

        return $this->pass('origins_pure', 'Production origin form', 'APP_URL and ARTIFACT_URL are pure origins.');
    }

    private function databaseDriverCheck(bool $production): DoctorCheck
    {
        $driver = $this->string('database.default');

        if (SecurityInvariants::isSupportedDatabaseDriver($driver)) {
            return $this->pass('database_driver', 'Database driver', 'Using PostgreSQL.');
        }

        $detail = sprintf('Using %s; ArtifactFlow only supports PostgreSQL.', $driver === '' ? 'an unset driver' : $driver);

        return $production
            ? $this->fail('database_driver', 'Database driver', 'Set DB_CONNECTION=pgsql; ArtifactFlow only supports PostgreSQL.')
            : $this->warn('database_driver', 'Database driver', $detail);
    }

    private function databasePasswordCheck(bool $production): DoctorCheck
    {
        if (!$production) {
            return $this->skipped('database_password', 'Database password', 'Local credentials apply; production must set a non-placeholder database password.');
        }

        $password = $this->string('database.connections.pgsql.password');

        if (!SecurityInvariants::databasePasswordIsAcceptable($password)) {
            return $this->fail('database_password', 'Database password', 'Set DB_PASSWORD to a real, non-placeholder secret in production.');
        }

        // Mirror the boot gate (ProductionSecurityConfiguration::ensureDatabasePassword),
        // which also rejects the passwords published in this repository's compose and
        // example files. Without this the doctor would grade green on a config the
        // production boot then aborts on.
        if (SecurityInvariants::databasePasswordIsPublishedFixture($password)) {
            return $this->fail('database_password', 'Database password', 'Set DB_PASSWORD to a value not published in this repository; the boot gate rejects the development defaults.');
        }

        return $this->pass('database_password', 'Database password', 'A non-placeholder database password is configured.');
    }

    private function databaseTlsCheck(bool $production): DoctorCheck
    {
        if ($this->string('database.default') !== 'pgsql') {
            return $this->skipped('database_tls', 'Database TLS', 'Non-PostgreSQL default connection.');
        }

        $sslmode = strtolower($this->string('database.connections.pgsql.sslmode'));
        $enforced = SecurityInvariants::postgresSslModeIsVerifyFull($sslmode)
            && SecurityInvariants::postgresRootCertIsConfigured($this->string('database.connections.pgsql.sslrootcert'));

        if (!$production) {
            return $this->skipped('database_tls', 'Database TLS', sprintf('sslmode=%s; production requires verify-full with a root certificate.', $sslmode === '' ? 'unset' : $sslmode));
        }

        return $enforced
            ? $this->pass('database_tls', 'Database TLS', 'PostgreSQL uses verify-full with an explicit root certificate.')
            : $this->fail('database_tls', 'Database TLS', 'Set DB_SSLMODE=verify-full and DB_SSLROOTCERT in production.');
    }

    private function secureSessionCheck(bool $production): DoctorCheck
    {
        $secure = $this->config->get('session.secure') === true
            && $this->config->get('session.encrypt') === true
            && $this->config->get('session.http_only') === true
            && in_array(strtolower($this->string('session.same_site')), ['lax', 'strict'], true)
            && $this->string('session.driver') === 'database';

        if (!$production) {
            return $this->skipped('secure_sessions', 'Session hardening', 'Relaxed session flags are fine locally; production requires secure, encrypted, http-only, same-site database sessions.');
        }

        return $secure
            ? $this->pass('secure_sessions', 'Session hardening', 'Sessions are secure, encrypted, http-only, and same-site.')
            : $this->fail('secure_sessions', 'Session hardening', 'Enable secure, encrypted, http-only, lax/strict database sessions in production.');
    }

    private function trustedProxiesCheck(bool $production): DoctorCheck
    {
        $raw = $this->string('trustedproxy.raw');
        $valid = SecurityInvariants::trustedProxiesAreConfigured($raw)
            && !SecurityInvariants::trustedProxiesUseWildcard($raw)
            && !SecurityInvariants::trustedProxiesUseBroadDockerCidr($raw)
            && !SecurityInvariants::trustedProxiesUseAllAddressesCidr($raw);

        if (!$production) {
            return $this->skipped('trusted_proxies', 'Trusted proxies', 'Development proxy defaults apply; production must name the real TLS edge.');
        }

        if (!$valid) {
            return $this->fail('trusted_proxies', 'Trusted proxies', 'Set TRUSTED_PROXIES to the real edge; no wildcard or broad Docker CIDR.');
        }

        if (SecurityInvariants::trustedProxiesTrustImmediatePeer($raw)) {
            return $this->warn(
                'trusted_proxies',
                'Trusted proxies',
                'TRUSTED_PROXIES=REMOTE_ADDR trusts whatever connects directly, so expose the app port only to the edge proxy; a directly reachable app would let clients spoof X-Forwarded-For and defeat IP-keyed rate limits and audit trails.',
            );
        }

        return $this->pass('trusted_proxies', 'Trusted proxies', 'Trusted proxies name explicit edge addresses.');
    }

    private function artifactStoragePrivateCheck(bool $production): DoctorCheck
    {
        $private = $this->string('filesystems.disks.artifacts.visibility') === 'private';
        $outsidePublicPath = SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
            $this->string('filesystems.disks.artifacts.root'),
            public_path(),
        );

        if (!$outsidePublicPath) {
            return $this->fail(
                'artifact_storage',
                'Artifact storage',
                'Set ARTIFACT_STORAGE_ROOT outside the public web root so artifact bytes cannot be served from the application origin.',
            );
        }

        if (!$production) {
            return $private
                ? $this->pass('artifact_storage', 'Artifact storage', 'Artifact disk visibility is private. Its root is outside the public web root.')
                : $this->warn('artifact_storage', 'Artifact storage', 'Artifact disk visibility is not private.');
        }

        return $private
            ? $this->pass('artifact_storage', 'Artifact storage', 'Artifact disk visibility is private. Its root is outside the public web root.')
            : $this->fail('artifact_storage', 'Artifact storage', 'Artifact storage must be private in production.');
    }

    private function noPersistentAdminPasswordCheck(bool $production): DoctorCheck
    {
        $configured = $this->string('app.bootstrap_admin_password') !== ''
            || $this->string('app.create_user_password') !== ''
            || $this->string('app.reset_user_password') !== '';

        if (!$production) {
            return $this->skipped('bootstrap_passwords', 'Bootstrap passwords', 'One-shot bootstrap passwords are convenient locally; production must not persist them.');
        }

        return $configured
            ? $this->fail('bootstrap_passwords', 'Bootstrap passwords', 'Remove ARTIFACTFLOW_ADMIN_PASSWORD and related fallbacks in production.')
            : $this->pass('bootstrap_passwords', 'Bootstrap passwords', 'No persistent bootstrap passwords are configured.');
    }

    private function isProduction(): bool
    {
        $environment = strtolower($this->string('app.env'));

        return $environment === 'production';
    }

    private function pass(string $id, string $label, string $detail): DoctorCheck
    {
        return new DoctorCheck($id, $label, DoctorCheckStatus::Pass, $detail);
    }

    private function warn(string $id, string $label, string $detail): DoctorCheck
    {
        return new DoctorCheck($id, $label, DoctorCheckStatus::Warn, $detail);
    }

    private function fail(string $id, string $label, string $detail): DoctorCheck
    {
        return new DoctorCheck($id, $label, DoctorCheckStatus::Fail, $detail);
    }

    private function skipped(string $id, string $label, string $detail): DoctorCheck
    {
        return new DoctorCheck($id, $label, DoctorCheckStatus::Skipped, $detail);
    }

    private function isValidSecret(string $secret): bool
    {
        return SecretStrength::isStrong($secret);
    }

    private function normalizedSecret(string $secret): ?string
    {
        return SecretStrength::normalized($secret);
    }

    private function configuredBcryptRounds(): int
    {
        return SecurityInvariants::configuredBcryptRounds($this->config)
            ?? ProductionSecurityConfiguration::DEFAULT_BCRYPT_ROUNDS;
    }

    private function hostOf(string $urlOrHost): ?string
    {
        return OriginNormalizer::tryHost($urlOrHost);
    }

    private function origin(string $url): ?string
    {
        return OriginNormalizer::tryParse($url)?->canonical();
    }

    private function positiveInt(string $key): ?int
    {
        $value = $this->config->get($key);

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
