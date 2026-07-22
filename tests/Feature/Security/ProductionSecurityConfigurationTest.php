<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Infrastructure\Security\ProductionSecurityConfiguration;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductionSecurityConfigurationTest extends TestCase
{
    public function test_safe_production_security_configuration_is_accepted(): void
    {
        $this->configureSafeProductionValues();

        app(ProductionSecurityConfiguration::class)->ensureSafe();

        $this->addToAssertionCount(1);
    }

    public function test_same_application_and_artifact_origin_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        // A bare origin equal to app.url: the pure-origin parser accepts it (no path,
        // userinfo, query, or fragment), so the config reaches the distinctness comparison
        // and is rejected there rather than as an invalid origin form.
        config(['app.artifact_url' => 'https://app.example.test']);

        $this->assertUnsafeConfiguration('Application and artifact origins must be different.');
    }

    public function test_same_application_and_artifact_host_is_rejected_even_with_distinct_origins(): void
    {
        $this->configureSafeProductionValues();
        // Distinct origins that share a host (only the port differs). Cookies
        // ignore the port, so a host-only app session cookie would still be sent
        // to the artifact origin, collapsing the cookieless-artifact isolation.
        // A null session domain (host-only cookies) is what makes this leak; the
        // dedicated session-domain check returns early for it, so the host guard
        // is the only thing standing between this config and a live cookie leak.
        config([
            'app.artifact_url' => 'https://app.example.test:8443',
            'session.domain' => null,
        ]);

        $this->assertUnsafeConfiguration('Application and artifact hosts must be different.');
    }

    public function test_non_canonical_ipv4_alias_origins_are_rejected_as_invalid(): void
    {
        // A browser canonicalizes these to a dotted-quad, so an operator could point
        // one origin at a shorthand/integer/hex spelling of the same address the other
        // uses in dotted-quad form and slip past the host-separation gate. The origin
        // parser fails closed on every non-canonical numeric spelling, so the boot gate
        // rejects them as invalid before any equality comparison.
        foreach (['https://127.1', 'https://2130706433', 'https://0x7f.0.0.1', 'https://0177.0.0.1'] as $aliasUrl) {
            $this->configureSafeProductionValues();
            config(['app.artifact_url' => $aliasUrl]);

            $this->assertUnsafeConfiguration('Configured production origin is invalid.');
        }
    }

    public function test_impure_production_origins_carrying_userinfo_path_query_or_fragment_are_rejected(): void
    {
        // A production origin must be a bare origin. parse_url() silently drops a userinfo,
        // path, query, or fragment (and a backslash or percent-escape) and keeps only the
        // origin -- but a browser resolves several of them elsewhere (`evil@host` makes
        // `host` the origin; a backslash is a path separator; %61 decodes to `a`). The boot
        // gate must reject them outright rather than boot on an origin the browser never sees.
        foreach ([
            'https://evil@artifacts.example.test',
            'https://user:pass@artifacts.example.test',
            'https://artifacts.example.test/pages',
            'https://artifacts.example.test?next=/x',
            'https://artifacts.example.test#frag',
            'https://artifacts.example.test\\@evil.example',
            'https://%61rtifacts.example.test',
        ] as $impureUrl) {
            $this->configureSafeProductionValues();
            config(['app.artifact_url' => $impureUrl]);

            $this->assertUnsafeConfiguration('Configured production origin is invalid.');
        }
    }

    public function test_ipv6_spelling_of_the_same_address_on_both_origins_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        // The app and artifact origins spell one IPv6 address two ways (compressed vs
        // fully expanded). Browsers treat them as one origin; the normalizer collapses
        // both to the canonical compressed form so the boot gate sees them as equal and
        // refuses to boot rather than believe the two origins are isolated.
        config([
            'app.url' => 'https://[::1]',
            'app.reverb_url' => 'https://[::1]',
            'app.artifact_frame_ancestors' => 'https://[::1]',
            'app.artifact_url' => 'https://[0:0:0:0:0:0:0:1]',
        ]);

        $this->assertUnsafeConfiguration('Application and artifact origins must be different.');
    }

    public function test_runtime_role_must_be_known_in_production(): void
    {
        foreach (['artifact_host', '', null] as $role) {
            $this->configureSafeProductionValues();
            config(['app.runtime_role' => $role]);

            $this->assertUnsafeConfiguration('Runtime role must be one of app, artifact-host, worker, or scheduler.');
        }
    }

    public function test_production_debug_mode_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        config(['app.debug' => true]);

        $this->assertUnsafeConfiguration('Debug mode must be disabled in production.');
    }

    public function test_production_origins_must_use_https(): void
    {
        $this->configureSafeProductionValues();
        config(['app.url' => 'http://app.example.test']);
        $this->assertUnsafeConfiguration('Configured production origins must use HTTPS.');

        $this->configureSafeProductionValues();
        config(['app.artifact_url' => 'http://artifacts.example.test']);
        $this->assertUnsafeConfiguration('Configured production origins must use HTTPS.');
    }

    public function test_missing_or_reused_artifact_signing_key_is_rejected_without_exposing_key_material(): void
    {
        $this->configureSafeProductionValues();
        config(['app.artifact_url_signing_key' => '']);
        $this->assertUnsafeConfiguration('Artifact preview signing key is required.');

        $this->configureSafeProductionValues();
        config(['app.artifact_url_signing_key' => 'base64:' . base64_encode(str_repeat('a', 32))]);

        try {
            app(ProductionSecurityConfiguration::class)->ensureSafe();
            $this->fail('Expected a reused application key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Artifact preview signing key must be dedicated.', $exception->getMessage());
            $this->assertStringNotContainsString(str_repeat('a', 32), $exception->getMessage());
        }

        $this->configureSafeProductionValues();
        config([
            'app.artifact_url_signing_key' => 'base64:' . base64_encode(str_repeat('c', 32)),
            'app.previous_keys' => ['base64:' . base64_encode(str_repeat('c', 32))],
        ]);
        $this->assertUnsafeConfiguration('Artifact preview signing key must be dedicated.');
    }

    public function test_repo_published_artifact_signing_key_is_rejected_in_production(): void
    {
        $this->configureSafeProductionValues();
        config(['app.artifact_url_signing_key' => 'artifact-preview-test-signing-key']);

        $this->assertUnsafeConfiguration('Artifact preview signing key must not be a published test key.');
    }

    public function test_non_delivering_or_unknown_mail_transport_is_rejected_in_production(): void
    {
        $message = 'Mail transport must be a real, deliverable transport in production. The log and array drivers discard invitation and password-reset emails, and an unknown mailer fails only when the first message is sent.';

        // 'log' leaks token URLs to the log; 'array' silently drops mail; 'stmp' is a
        // typo that is not a configured mailer and would only fail at send time; unset
        // is not deliverable either. All must fail closed at boot.
        foreach (['log', 'array', 'stmp', ''] as $mailer) {
            $this->configureSafeProductionValues();
            config(['mail.default' => $mailer]);

            $this->assertUnsafeConfiguration($message);
        }

        $this->configureSafeProductionValues();
        config([
            'mail.default' => 'resend',
            'services.resend.key' => '',
        ]);
        $this->assertUnsafeConfiguration($message);

        $this->configureSafeProductionValues();
        config([
            'mail.default' => 'resend',
            'services.resend.key' => 're_test_delivery_key',
        ]);
        app(ProductionSecurityConfiguration::class)->ensureSafe();
        $this->addToAssertionCount(1);

        // A real, configured transport boots.
        $this->configureSafeProductionValues();
        config(['mail.default' => 'smtp']);
        app(ProductionSecurityConfiguration::class)->ensureSafe();
        $this->addToAssertionCount(1);
    }

    public function test_invitation_queue_must_share_the_primary_database_transaction_in_production(): void
    {
        $message = 'Invitation delivery queue must use the primary database connection with after-commit dispatch disabled in production.';

        foreach ([
            ['queue.default' => 'sync'],
            ['queue.connections.database.connection' => 'queue_database'],
            ['queue.connections.database.after_commit' => true],
        ] as $unsafeConfiguration) {
            $this->configureSafeProductionValues();
            config($unsafeConfiguration);

            $this->assertUnsafeConfiguration($message);
        }

        $this->configureSafeProductionValues();
        config(['queue.connections.database.connection' => 'pgsql']);

        app(ProductionSecurityConfiguration::class)->ensureSafe();
        $this->addToAssertionCount(1);
    }

    public function test_repo_published_database_password_is_rejected_in_production(): void
    {
        foreach (['app_local_password', 'postgres', 'postgres_test_password'] as $published) {
            $this->configureSafeProductionValues();
            config(['database.connections.pgsql.password' => $published]);

            $this->assertUnsafeConfiguration('Database password must not be a published development default in production.');
        }
    }

    public function test_short_invalid_or_placeholder_application_and_artifact_keys_are_rejected(): void
    {
        foreach ([
            ['app.key' => ''],
            ['app.key' => 'base64:not-valid-base64'],
            ['app.key' => 'base64:' . base64_encode('too-short')],
            ['app.key' => 'base64:replace-with-a-generated-production-key'],
        ] as $unsafeKey) {
            $this->configureSafeProductionValues();
            config($unsafeKey);

            $this->assertUnsafeConfiguration('Application key must be a non-placeholder 32-byte secret.');
        }

        foreach ([
            ['app.artifact_url_signing_key' => 'base64:not-valid-base64'],
            ['app.artifact_url_signing_key' => 'base64:' . base64_encode('too-short')],
            ['app.artifact_url_signing_key' => 'base64:replace-with-a-generated-artifact-signing-key'],
        ] as $unsafeKey) {
            $this->configureSafeProductionValues();
            config($unsafeKey);

            $this->assertUnsafeConfiguration('Artifact preview signing key must be a non-placeholder 32-byte secret.');
        }
    }

    public function test_production_sessions_must_be_secure_encrypted_http_only_and_same_site_bound(): void
    {
        $this->configureSafeProductionValues();
        config(['session.secure' => false]);
        $this->assertUnsafeConfiguration('Session cookies must be secure in production.');

        $this->configureSafeProductionValues();
        config(['session.encrypt' => false]);
        $this->assertUnsafeConfiguration('Session payloads must be encrypted in production.');

        $this->configureSafeProductionValues();
        config(['session.http_only' => false]);
        $this->assertUnsafeConfiguration('Session cookies must be HTTP-only in production.');

        foreach (['none', '', null] as $sameSite) {
            $this->configureSafeProductionValues();
            config(['session.same_site' => $sameSite]);

            $this->assertUnsafeConfiguration('Session cookies must use lax or strict SameSite in production.');
        }

        $this->configureSafeProductionValues();
        config(['session.driver' => 'file']);
        $this->assertUnsafeConfiguration('Session driver must be database in production.');
    }

    public function test_session_domain_must_not_cover_the_artifact_origin(): void
    {
        $this->configureSafeProductionValues();
        config(['session.domain' => '.example.test']);

        $this->assertUnsafeConfiguration('Session domain must not cover the artifact origin.');

        $this->configureSafeProductionValues();
        config(['session.domain' => 'app.example.test']);

        app(ProductionSecurityConfiguration::class)->ensureSafe();
        $this->addToAssertionCount(1);
    }

    public function test_non_ascii_idn_session_domain_is_rejected_rather_than_compared_byte_wise(): void
    {
        // A browser IDNA-canonicalises the cookie Domain attribute (münchen.de ->
        // xn--mnchen-3ya.de), so a raw-unicode SESSION_DOMAIN would be byte-compared here
        // against the ASCII/punycode artifact host and could slip past the coverage suffix
        // test while the browser still scopes the app session cookie over a domain that
        // covers the artifact origin. Routing SESSION_DOMAIN through the single origin parser
        // (which rejects non-ASCII) fails the gate closed and demands the punycode spelling.
        $this->configureSafeProductionValues();
        config(['session.domain' => 'münchen.de']);

        $this->assertUnsafeConfiguration('Session domain must be a canonical ASCII host.');
    }

    public function test_production_requires_the_postgresql_driver(): void
    {
        foreach (['sqlite', 'mysql', 'mariadb', ''] as $driver) {
            $this->configureSafeProductionValues();
            config(['database.default' => $driver]);

            $this->assertUnsafeConfiguration('Database connection must be PostgreSQL (pgsql) in production.');
        }
    }

    public function test_production_requires_a_non_placeholder_database_password(): void
    {
        foreach (['', '   ', 'replace-with-a-strong-password', 'change-me'] as $password) {
            $this->configureSafeProductionValues();
            config(['database.connections.pgsql.password' => $password]);

            $this->assertUnsafeConfiguration('Database password must be a real, non-placeholder secret in production.');
        }
    }

    public function test_production_database_transport_must_verify_full_tls(): void
    {
        foreach (['disable', 'allow', 'prefer', 'require', 'verify-ca', '', null] as $sslMode) {
            $this->configureSafeProductionValues();
            config(['database.connections.pgsql.sslmode' => $sslMode]);

            $this->assertUnsafeConfiguration('PostgreSQL sslmode must be verify-full in production.');
        }

        $this->configureSafeProductionValues();
        config(['database.connections.pgsql.sslrootcert' => null]);

        $this->assertUnsafeConfiguration('PostgreSQL verify-full requires an explicit root certificate in production.');

        $this->configureSafeProductionValues();
        config(['database.connections.pgsql.sslrootcert' => '/missing/artifactflow-postgres-ca.pem']);

        $this->assertUnsafeConfiguration('PostgreSQL verify-full requires a readable root certificate file in production.');

        $this->configureSafeProductionValues();
        config(['database.connections.pgsql.sslmode' => 'verify-full']);

        app(ProductionSecurityConfiguration::class)->ensureSafe();
        $this->addToAssertionCount(1);
    }

    public function test_dummy_password_hash_cost_must_match_configured_bcrypt_rounds(): void
    {
        $this->configureSafeProductionValues();
        config([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 13,
        ]);

        $this->assertUnsafeConfiguration('Dummy password hash bcrypt cost must match configured bcrypt rounds.');
    }

    public function test_unsafe_artifact_frame_ancestors_are_rejected(): void
    {
        foreach (['*', 'https://other.example.test'] as $frameAncestors) {
            $this->configureSafeProductionValues();
            config(['app.artifact_frame_ancestors' => $frameAncestors]);

            $this->assertUnsafeConfiguration('Artifact frame ancestors must include only the application origin.');
        }
    }

    public function test_public_artifact_storage_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        config(['filesystems.disks.artifacts.visibility' => 'public']);

        $this->assertUnsafeConfiguration('Artifact storage must be private.');
    }

    public function test_artifact_storage_inside_the_public_web_root_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        config(['filesystems.disks.artifacts.root' => public_path('artifacts')]);

        $this->assertUnsafeConfiguration('Artifact storage root must be outside the public web root.');
    }

    public function test_non_shared_cache_store_is_rejected(): void
    {
        // 'array' resolves to the array driver; 'null' and '' resolve to no defined store.
        foreach (['array', 'null', ''] as $store) {
            $this->configureSafeProductionValues();
            config(['cache.default' => $store]);

            $this->assertUnsafeConfiguration(
                'Cache store must share rate-limit counters across production app replicas. The rate limiter store (cache.limiter or cache.default) must resolve to a defined database, Redis, Memcached, or DynamoDB cache driver.',
            );
        }
    }

    public function test_node_local_file_cache_is_rejected_for_rate_limiting(): void
    {
        $this->configureSafeProductionValues();
        config(['cache.default' => 'file']);

        $this->assertUnsafeConfiguration(
            'Cache store must share rate-limit counters across production app replicas. The rate limiter store (cache.limiter or cache.default) must resolve to a defined database, Redis, Memcached, or DynamoDB cache driver.',
        );
    }

    public function test_cache_store_backed_by_the_array_driver_is_rejected_behind_a_custom_alias(): void
    {
        $this->configureSafeProductionValues();
        // A persistent-sounding store name whose driver is actually array: the name-only
        // check would have blessed this, but the throttle counters still evaporate.
        config([
            'cache.stores.sneaky' => ['driver' => 'array'],
            'cache.default' => 'sneaky',
        ]);

        $this->assertUnsafeConfiguration(
            'Cache store must share rate-limit counters across production app replicas. The rate limiter store (cache.limiter or cache.default) must resolve to a defined database, Redis, Memcached, or DynamoDB cache driver.',
        );
    }

    public function test_dedicated_limiter_store_is_validated_over_a_persistent_default(): void
    {
        $this->configureSafeProductionValues();
        // The rate limiters use cache.limiter when set, so a transient limiter store
        // defeats throttling even though the default store persists.
        config([
            'cache.stores.throttle' => ['driver' => 'array'],
            'cache.default' => 'database',
            'cache.limiter' => 'throttle',
        ]);

        $this->assertUnsafeConfiguration(
            'Cache store must share rate-limit counters across production app replicas. The rate limiter store (cache.limiter or cache.default) must resolve to a defined database, Redis, Memcached, or DynamoDB cache driver.',
        );
    }

    public function test_persistent_limiter_store_passes_even_when_the_default_store_is_transient(): void
    {
        $this->configureSafeProductionValues();
        // Mirror image: a persistent dedicated limiter store is enough, whatever the default.
        config([
            'cache.default' => 'array',
            'cache.limiter' => 'database',
        ]);

        app(ProductionSecurityConfiguration::class)->ensureSafe();

        $this->addToAssertionCount(1);
    }

    public function test_reverb_secret_is_required_when_reverb_broadcasting_is_enabled(): void
    {
        foreach (['', 'replace-with-reverb-secret', 'short-secret'] as $secret) {
            $this->configureSafeProductionValues();
            config([
                'broadcasting.default' => 'reverb',
                'broadcasting.connections.reverb.secret' => $secret,
            ]);

            $this->assertUnsafeConfiguration('Reverb app secret must be a non-placeholder 32-byte secret.');
        }
    }

    public function test_reverb_public_origin_must_match_the_application_origin_in_production(): void
    {
        $this->configureSafeProductionValues();
        config([
            'app.reverb_url' => 'https://realtime.example.test',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
        ]);

        $this->assertUnsafeConfiguration('Reverb public origin must match the application origin.');
    }

    public function test_reverb_allowed_origins_must_be_limited_to_the_application_origin_in_production(): void
    {
        foreach ([['*'], ['https://evil.example.test'], ['evil.example.test'], ['*.example.test']] as $allowedOrigins) {
            $this->configureSafeProductionValues();
            config([
                'broadcasting.default' => 'reverb',
                'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
                'reverb.apps.apps.0.allowed_origins' => $allowedOrigins,
            ]);

            $this->assertUnsafeConfiguration('Reverb allowed origins must include only the application origin.');
        }

        foreach ([['http://app.example.test'], ['https://evil.example.test'], ['evil.example.test']] as $configuredOrigins) {
            $this->configureSafeProductionValues();
            config([
                'broadcasting.default' => 'reverb',
                'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
                'reverb.apps.apps.0.configured_allowed_origins' => $configuredOrigins,
            ]);

            $this->assertUnsafeConfiguration('Reverb allowed origins must include only the application origin.');
        }
    }

    public function test_reverb_client_events_must_be_rate_limited_and_connection_bounded_in_production(): void
    {
        $this->configureSafeProductionValues();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
            'reverb.apps.apps.0.rate_limiting.enabled' => false,
        ]);

        $this->assertUnsafeConfiguration('Reverb client-event rate limiting must be enabled in production.');

        foreach ([null, '', 0, '0', -1] as $maxConnections) {
            $this->configureSafeProductionValues();
            config([
                'broadcasting.default' => 'reverb',
                'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
                'reverb.apps.apps.0.max_connections' => $maxConnections,
            ]);

            $this->assertUnsafeConfiguration('Reverb max connections must be bounded in production.');
        }
    }

    public function test_reverb_config_defaults_to_client_event_rate_limiting(): void
    {
        $config = file_get_contents(config_path('reverb.php'));
        $this->assertIsString($config);
        $this->assertStringContainsString("env('REVERB_APP_RATE_LIMITING_ENABLED', true)", $config);
        $this->assertStringContainsString("env('REVERB_APP_MAX_CONNECTIONS', 1000)", $config);
        $this->assertStringContainsString('configured_allowed_origins', $config);
        // The Reverb-enforced allowed-origin hosts are derived through the single origin
        // parser (not an ad-hoc parse_url + strtolower), so the runtime allowlist matches the
        // browser-canonical and boot-gate-validated host spelling (e.g. compressed IPv6).
        $this->assertStringContainsString('OriginNormalizer::tryHost($origin)', $config);
    }

    public function test_reverb_broadcasting_passes_with_a_valid_secret_and_application_origin(): void
    {
        $this->configureSafeProductionValues();
        config([
            'app.reverb_url' => 'https://app.example.test',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
        ]);

        app(ProductionSecurityConfiguration::class)->ensureSafe();

        $this->addToAssertionCount(1);
    }

    public function test_missing_system_admin_bootstrap_path_is_rejected(): void
    {
        $this->configureSafeProductionValues();
        config(['app.bootstrap_admin_command' => '']);

        $this->assertUnsafeConfiguration('System Admin bootstrap path is required.');
    }

    public function test_registered_production_boot_path_fails_closed_for_unsafe_configuration(): void
    {
        $this->assertUnsafeBootPathForEnv('production', 'Application and artifact origins must be different.');
        $this->assertUnsafeBootPathForEnv('Production', 'Application and artifact origins must be different.');
        $this->assertUnsafeBootPathForEnv('production ', 'Application and artifact origins must be different.');
        $this->assertUnsafeBootPathForEnv('prod', 'APP_ENV must be one of local, testing, build, or production.');
    }

    public function test_the_doctor_recovery_command_still_boots_under_an_unsafe_production_configuration(): void
    {
        // The doctor is the operator's read-only diagnostic: if the boot gate aborted
        // it too, an unsafe production config would hide its own punch list, leaving no
        // way to see what is wrong. It must boot and grade even the unsafe config that
        // fails closed for every non-exempt command.
        $doctor = $this->runBootPathCommand(['artifactflow:doctor'], 'production');
        $output = $doctor->getOutput() . $doctor->getErrorOutput();

        $this->assertStringContainsString('ArtifactFlow doctor (production mode)', $output);
        $this->assertStringContainsString('App and artifact origins', $output);
        $this->assertStringNotContainsString('Application and artifact origins must be different.', $output);
    }

    public function test_ordinary_commands_still_fail_closed_under_the_same_unsafe_configuration(): void
    {
        // The exemption is narrow: only the recovery commands bypass the gate. A normal
        // command (here route:list, which a worker or scheduler boot resembles) must
        // still abort so an unsafe production config never serves or processes work.
        $process = $this->runBootPathCommand(['route:list', '--no-interaction'], 'production');
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('Application and artifact origins must be different.', $output);
    }

    public function test_persistent_configured_passwords_are_rejected_in_production(): void
    {
        $this->configureSafeProductionValues();
        config(['app.bootstrap_admin_password' => 'correct horse battery staple']);
        $this->assertUnsafeConfiguration('System Admin bootstrap password must not be configured persistently in production.');

        $this->configureSafeProductionValues();
        config(['app.create_user_password' => 'correct horse battery staple']);
        $this->assertUnsafeConfiguration('User creation password fallback must not be configured persistently in production.');

        $this->configureSafeProductionValues();
        config(['app.reset_user_password' => 'correct horse battery staple']);
        $this->assertUnsafeConfiguration('Password reset fallback must not be configured persistently in production.');
    }

    public function test_one_shot_password_file_inputs_can_boot_each_operator_command_in_production(): void
    {
        $secretFile = storage_path('framework/testing/production-command-password-' . bin2hex(random_bytes(8)));
        file_put_contents($secretFile, "password from a mounted secret\n");

        try {
            foreach ([
                ['artifactflow:bootstrap-admin', 'ARTIFACTFLOW_ADMIN_PASSWORD_FILE'],
                ['artifactflow:create-user', 'ARTIFACTFLOW_CREATE_USER_PASSWORD_FILE'],
                ['artifactflow:reset-password', 'ARTIFACTFLOW_RESET_PASSWORD_FILE'],
            ] as [$command, $environmentVariable]) {
                $process = $this->runSafeProductionBootPathCommand(
                    [$command, '--help', '--no-interaction'],
                    [$environmentVariable => $secretFile],
                );
                $output = $process->getOutput() . $process->getErrorOutput();

                $this->assertSame(0, $process->getExitCode(), $output);
                $this->assertStringContainsString($command, $output);
                $this->assertStringNotContainsString('password from a mounted secret', $output);
                $this->assertStringNotContainsString('must not be configured persistently', $output);
            }
        } finally {
            unlink($secretFile);
        }
    }

    public function test_production_trusted_proxies_must_be_explicit_and_not_the_wide_development_cidr(): void
    {
        $this->configureSafeProductionValues();
        config(['trustedproxy.raw' => '']);
        $this->assertUnsafeConfiguration('Trusted proxies must be explicitly configured for production.');

        $this->configureSafeProductionValues();
        config(['trustedproxy.raw' => '127.0.0.1,::1,172.16.0.0/12']);
        $this->assertUnsafeConfiguration('Trusted proxies must not use the broad development Docker CIDR in production.');
    }

    public function test_production_trusted_proxies_must_not_use_an_all_addresses_cidr(): void
    {
        foreach (['0.0.0.0/0', '::/0', '127.0.0.1,0.0.0.0/0', '10.0.0.1, ::/0'] as $trustEverything) {
            $this->configureSafeProductionValues();
            config(['trustedproxy.raw' => $trustEverything]);
            $this->assertUnsafeConfiguration(
                'Trusted proxies must not use an all-addresses CIDR (for example 0.0.0.0/0 or ::/0) in production.',
            );
        }
    }

    public function test_production_trusted_proxies_allow_a_specific_edge_cidr(): void
    {
        $this->configureSafeProductionValues();
        config(['trustedproxy.raw' => '203.0.113.7,2001:db8::/32,10.0.0.0/24']);

        // A concrete edge with narrow, non-zero-prefix CIDRs must boot without error.
        app(ProductionSecurityConfiguration::class)->ensureSafe();

        $this->assertSame('203.0.113.7,2001:db8::/32,10.0.0.0/24', config('trustedproxy.raw'));
    }

    public function test_artifact_read_limit_must_cover_html_write_limit(): void
    {
        $this->configureSafeProductionValues();
        config([
            'pages.max_html_bytes' => 1024,
            'pages.artifact_max_bytes' => 512,
        ]);

        $this->assertUnsafeConfiguration('Artifact read limit must be greater than or equal to every content write limit.');
    }

    public function test_artifact_read_limit_must_cover_markdown_write_limit(): void
    {
        $this->configureSafeProductionValues();
        config([
            'pages.max_markdown_bytes' => 1024,
            'pages.max_html_bytes' => 256,
            'pages.artifact_max_bytes' => 512,
        ]);

        $this->assertUnsafeConfiguration('Artifact read limit must be greater than or equal to every content write limit.');
    }

    public function test_html_write_limit_must_fit_the_production_http_request_envelope(): void
    {
        $this->configureSafeProductionValues();
        config([
            'pages.max_html_bytes' => 5 * 1024 * 1024 + 1,
            'pages.artifact_max_bytes' => 10 * 1024 * 1024,
        ]);

        $this->assertUnsafeConfiguration('HTML write limit must not exceed the production HTTP request envelope.');
    }

    private function configureSafeProductionValues(): void
    {
        config([
            'app.url' => 'https://app.example.test',
            'app.debug' => false,
            'app.env' => 'production',
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.previous_keys' => [],
            'app.reverb_url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.artifact_frame_ancestors' => 'https://app.example.test',
            'app.artifact_url_signing_key' => 'base64:' . base64_encode(str_repeat('b', 32)),
            'app.runtime_role' => 'app',
            'app.bootstrap_admin_command' => 'artifactflow:bootstrap-admin',
            'app.bootstrap_admin_password' => null,
            'app.create_user_password' => null,
            'app.reset_user_password' => null,
            'cache.default' => 'database',
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.after_commit' => false,
            'broadcasting.default' => 'null',
            'broadcasting.connections.reverb.app_id' => 'artifactflow-smoke-test',
            'broadcasting.connections.reverb.key' => 'artifactflow-smoke-test-key',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
            'database.default' => 'pgsql',
            'database.connections.pgsql.password' => 'app-local-strong-password',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.sslrootcert' => '/etc/ssl/certs/ca-certificates.crt',
            'pages.artifact_max_bytes' => 1024 * 1024,
            'pages.max_html_bytes' => 1024 * 1024,
            'pages.max_markdown_bytes' => 1024 * 1024,
            'reverb.apps.apps.0.configured_allowed_origins' => ['https://app.example.test'],
            'reverb.apps.apps.0.allowed_origins' => ['app.example.test'],
            'reverb.apps.apps.0.max_connections' => 1000,
            'reverb.apps.apps.0.rate_limiting.enabled' => true,
            'filesystems.disks.artifacts.visibility' => 'private',
            'hashing.bcrypt.rounds' => 12,
            'hashing.driver' => 'bcrypt',
            'session.driver' => 'database',
            'session.domain' => 'app.example.test',
            'session.encrypt' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.secure' => true,
            'trustedproxy.raw' => 'REMOTE_ADDR',
            'trustedproxy.proxies' => 'REMOTE_ADDR',
        ]);
    }

    private function assertUnsafeConfiguration(string $message): void
    {
        try {
            app(ProductionSecurityConfiguration::class)->ensureSafe();
            $this->fail('Expected unsafe production security configuration to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertUnsafeBootPathForEnv(string $environment, string $message): void
    {
        $process = $this->runBootPathCommand(['route:list', '--no-interaction'], $environment);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString($message, $output);
    }

    /**
     * Boot the real application in a subprocess with a deliberately unsafe production
     * configuration (APP_URL and ARTIFACT_URL share an origin) and run the given
     * artisan command, so tests can observe whether the boot gate fires for it.
     *
     * @param list<string> $command
     */
    private function runBootPathCommand(array $command, string $environment): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$command],
            base_path(),
            [
                'APP_DEBUG' => 'false',
                'APP_ENV' => $environment,
                'APP_KEY' => 'base64:' . base64_encode(str_repeat('a', 32)),
                'APP_RUNTIME_ROLE' => 'app',
                'APP_URL' => 'https://app.example.test',
                'ARTIFACT_FRAME_ANCESTORS' => 'https://app.example.test',
                'ARTIFACT_URL' => 'https://app.example.test',
                'ARTIFACT_URL_SIGNING_KEY' => 'base64:' . base64_encode(str_repeat('b', 32)),
                'ARTIFACTFLOW_ADMIN_PASSWORD' => '',
                'ARTIFACTFLOW_CREATE_USER_PASSWORD' => '',
                'ARTIFACTFLOW_RESET_PASSWORD' => '',
                'SESSION_DRIVER' => 'database',
                'SESSION_ENCRYPT' => 'true',
                'SESSION_HTTP_ONLY' => 'true',
                'SESSION_SAME_SITE' => 'lax',
                'SESSION_SECURE_COOKIE' => 'true',
                'TRUSTED_PROXIES' => 'REMOTE_ADDR',
            ],
            null,
            30,
        );

        $process->run();

        return $process;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $extraEnvironment
     */
    private function runSafeProductionBootPathCommand(array $command, array $extraEnvironment): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$command],
            base_path(),
            [
                'APP_DEBUG' => 'false',
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                'APP_PREVIOUS_KEYS' => '',
                'APP_RUNTIME_ROLE' => 'app',
                'APP_URL' => 'https://app.example.test',
                'ARTIFACT_FRAME_ANCESTORS' => 'https://app.example.test',
                'ARTIFACT_URL' => 'https://artifacts.example.test',
                'ARTIFACT_URL_SIGNING_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                'ARTIFACTFLOW_ADMIN_PASSWORD' => '',
                'ARTIFACTFLOW_CREATE_USER_PASSWORD' => '',
                'ARTIFACTFLOW_RESET_PASSWORD' => '',
                'BCRYPT_ROUNDS' => '12',
                'BROADCAST_CONNECTION' => 'null',
                'CACHE_STORE' => 'database',
                'DB_CONNECTION' => 'pgsql',
                'DB_PASSWORD' => bin2hex(random_bytes(24)),
                'DB_SSLMODE' => 'verify-full',
                'DB_SSLROOTCERT' => base_path('README.md'),
                'MAIL_MAILER' => 'smtp',
                'QUEUE_CONNECTION' => 'database',
                'SESSION_DOMAIN' => 'app.example.test',
                'SESSION_DRIVER' => 'database',
                'SESSION_ENCRYPT' => 'true',
                'SESSION_HTTP_ONLY' => 'true',
                'SESSION_SAME_SITE' => 'lax',
                'SESSION_SECURE_COOKIE' => 'true',
                'TRUSTED_PROXIES' => 'REMOTE_ADDR',
                ...$extraEnvironment,
            ],
            null,
            30,
        );

        $process->run();

        return $process;
    }
}
