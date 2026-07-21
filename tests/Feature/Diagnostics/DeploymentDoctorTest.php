<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Application\Diagnostics\DeploymentDoctor;
use App\Application\Diagnostics\DoctorCheck;
use App\Application\Diagnostics\DoctorCheckStatus;
use App\Infrastructure\Security\ProductionSecurityConfiguration;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class DeploymentDoctorTest extends TestCase
{
    public function test_local_environment_passes_universal_checks_and_skips_production_hardening(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [])))->run();

        $this->assertFalse($report->production);
        $this->assertTrue($report->passed());
        $this->assertSame(DoctorCheckStatus::Pass, $this->check($report->checks, 'origins_distinct')->status);
        $this->assertSame(DoctorCheckStatus::Pass, $this->check($report->checks, 'app_key')->status);
        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'https_origins')->status);
        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'secure_sessions')->status);
        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'bootstrap_passwords')->status);
    }

    public function test_local_environment_still_fails_universal_invariants(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [
            'app.artifact_url' => 'http://localhost:18080',
            'app.key' => 'base64:' . base64_encode('short'),
        ])))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'origins_distinct')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'app_key')->status);
    }

    public function test_production_environment_grades_hardening_checks_as_failures(): void
    {
        $report = (new DeploymentDoctor($this->config('production', [
            'app.url' => 'http://app.example.test',
            'session.secure' => false,
            'trustedproxy.raw' => '*',
            'app.bootstrap_admin_password' => 'left-in-place',
        ])))->run();

        $this->assertTrue($report->production);
        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'https_origins')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'secure_sessions')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'trusted_proxies')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'bootstrap_passwords')->status);
    }

    public function test_well_configured_production_passes_every_check(): void
    {
        $report = (new DeploymentDoctor($this->config('production', $this->hardenedProductionConfig())))->run();

        $this->assertTrue($report->passed(), $this->describeFailures($report->checks));
    }

    public function test_production_fails_when_artifact_storage_is_inside_the_public_web_root(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'filesystems.disks.artifacts.root' => public_path('artifacts'),
        ]))))->run();

        $check = $this->check($report->checks, 'artifact_storage');

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $check->status);
        $this->assertStringContainsString('outside the public web root', $check->detail);
    }

    public function test_production_fails_when_the_cache_store_cannot_share_rate_limits(): void
    {
        foreach (['array', 'null', 'file'] as $store) {
            $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
                'cache.default' => $store,
            ]))))->run();

            $check = $this->check($report->checks, 'cache_store');

            $this->assertFalse($report->passed());
            $this->assertSame(DoctorCheckStatus::Fail, $check->status);
            $this->assertStringContainsString('does not provide shared counters', $check->detail);
        }
    }

    public function test_local_skips_the_cache_store_check(): void
    {
        $report = (new DeploymentDoctor($this->config('local', ['cache.default' => 'array'])))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'cache_store')->status);
    }

    public function test_production_fails_when_the_limiter_store_alias_is_backed_by_the_array_driver(): void
    {
        // cache.limiter selects the store, and its driver is what matters: a custom
        // alias backed by array defeats throttling even though its name looks bespoke.
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'cache.stores.throttle.driver' => 'array',
            'cache.limiter' => 'throttle',
        ]))))->run();

        $check = $this->check($report->checks, 'cache_store');

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $check->status);
        $this->assertStringContainsString('does not provide shared counters', $check->detail);
    }

    public function test_production_warns_but_still_passes_when_trusting_the_immediate_peer(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'trustedproxy.raw' => 'REMOTE_ADDR',
        ]))))->run();

        // REMOTE_ADDR is valid (not a wildcard), so the doctor passes overall, but it
        // warns because the safety of trusting the immediate peer hinges on the app
        // port being reachable only through the edge proxy.
        $this->assertTrue($report->passed(), $this->describeFailures($report->checks));
        $this->assertSame(DoctorCheckStatus::Warn, $this->check($report->checks, 'trusted_proxies')->status);
    }

    public function test_production_fails_on_a_non_ascii_session_domain_a_browser_would_canonicalise(): void
    {
        // Mirror the boot gate: a raw-unicode SESSION_DOMAIN is IDNA-canonicalised by browsers
        // to a punycode host that can cover the artifact origin, so grading it byte-wise hands
        // a false pass. Routing it through the single origin parser (which rejects non-ASCII)
        // fails the check, so the doctor cannot bless a config the next production boot rejects.
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'session.domain' => 'münchen.de',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'session_domain')->status);
    }

    public function test_production_grades_the_boot_gate_invariants_the_doctor_previously_missed(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'app.debug' => true,
            'auth.dummy_password_hash' => '',
            'session.domain' => 'example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.bootstrap_admin_command' => '',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'debug_disabled')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'dummy_password_hash')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'session_domain')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'bootstrap_command')->status);
    }

    public function test_local_fails_when_app_and_artifact_share_a_host_across_ports(): void
    {
        // The shipped local defaults keep the hosts apart (localhost vs 127.0.0.1)
        // because cookies ignore the port: on a shared host the app session cookie
        // rides along on every artifact request, in every environment. The doctor
        // must never bless a configuration that breaks that invariant, so a shared
        // host is a failure locally too, not a warning.
        $report = (new DeploymentDoctor($this->config('local', [
            'app.artifact_url' => 'http://localhost:18081',
        ])))->run();

        $this->assertFalse($report->passed());
        $check = $this->check($report->checks, 'origins_distinct');
        $this->assertSame(DoctorCheckStatus::Fail, $check->status);
        $this->assertStringContainsString('cookies ignore the port', $check->detail);
    }

    public function test_production_fails_when_app_and_artifact_share_a_host_across_distinct_origins(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            // Same host, different port: the origins are distinct, but cookies
            // ignore the port, so the app session cookie would still reach the
            // artifact origin. The preflight must catch this like the boot gate.
            'app.artifact_url' => 'https://app.example.test:8443',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'origins_distinct')->status);
    }

    public function test_production_fails_on_a_non_canonical_ipv4_alias_origin(): void
    {
        // The doctor must reject IPv4 shorthand/integer/hex/octal spellings the same
        // way the boot gate does: a browser canonicalizes them to a dotted-quad, so
        // grading them green would let one origin alias the other's address unseen.
        foreach (['https://127.1', 'https://2130706433', 'https://0x7f.0.0.1', 'https://0177.0.0.1'] as $aliasUrl) {
            $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
                'app.artifact_url' => $aliasUrl,
            ]))))->run();

            $this->assertFalse($report->passed(), sprintf('IPv4 alias [%s] must fail the doctor.', $aliasUrl));
            $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'origins_distinct')->status);
        }
    }

    public function test_production_fails_on_an_impure_origin_carrying_userinfo_path_query_or_fragment(): void
    {
        // A production origin must be a bare origin. parse_url() silently drops a userinfo,
        // path, query, fragment, backslash, or percent-escape and keeps only scheme://host:port,
        // but a browser resolves several of them elsewhere. Grade the same invariant the boot
        // gate enforces so the doctor cannot bless a config the next production boot would refuse.
        foreach ([
            'https://evil@artifacts.example.test',
            'https://artifacts.example.test/pages',
            'https://artifacts.example.test?next=/x',
            'https://artifacts.example.test#frag',
            'https://artifacts.example.test\\@evil.example',
            'https://%61rtifacts.example.test',
        ] as $impureUrl) {
            $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
                'app.artifact_url' => $impureUrl,
            ]))))->run();

            $this->assertFalse($report->passed(), sprintf('Impure origin [%s] must fail the doctor.', $impureUrl));
            $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'origins_pure')->status);
        }
    }

    public function test_local_skips_the_production_origin_purity_check(): void
    {
        // Origin purity is enforced only for production origins; locally the check is
        // skipped so a dev APP_URL carrying a path does not fail the diagnostic.
        $report = (new DeploymentDoctor($this->config('local', [])))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'origins_pure')->status);
    }

    public function test_production_fails_when_ipv6_origins_spell_the_same_address_two_ways(): void
    {
        // Compressed vs fully expanded IPv6 denote one browser origin. The normalizer
        // collapses both to the canonical form so the doctor grades them as the same
        // origin and fails, mirroring the boot gate rather than passing on an alias.
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'app.url' => 'https://[::1]',
            'app.artifact_frame_ancestors' => 'https://[::1]',
            'app.artifact_url' => 'https://[0:0:0:0:0:0:0:1]',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'origins_distinct')->status);
    }

    public function test_production_grades_database_driver_and_password(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'database.default' => 'sqlite',
            'database.connections.pgsql.password' => 'replace-with-a-strong-password',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'database_driver')->status);
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'database_password')->status);
    }

    public function test_production_fails_when_database_password_is_a_repository_published_default(): void
    {
        // The boot gate (ProductionSecurityConfiguration::ensureDatabasePassword) rejects
        // the compose/example defaults even though they are non-placeholder 32+ byte
        // strings; the doctor must fail them too, or it grades green on a config the
        // production boot then aborts on -- the exact drift SecurityInvariants forbids.
        foreach (['postgres', 'app_local_password', 'postgres_test_password'] as $published) {
            $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
                'database.connections.pgsql.password' => $published,
            ]))))->run();

            $this->assertFalse(
                $report->passed(),
                sprintf('Published development password [%s] must fail the doctor.', $published),
            );
            $this->assertSame(
                DoctorCheckStatus::Fail,
                $this->check($report->checks, 'database_password')->status,
                sprintf('Published development password [%s] must fail the database_password check.', $published),
            );
        }
    }

    public function test_local_warns_on_non_postgres_driver_without_failing(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [
            'database.default' => 'sqlite',
        ])))->run();

        $this->assertSame(DoctorCheckStatus::Warn, $this->check($report->checks, 'database_driver')->status);
        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($report->checks, 'database_password')->status);
    }

    public function test_production_dummy_hash_cost_must_match_configured_bcrypt_rounds(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'hashing.bcrypt.rounds' => 13,
        ]))))->run();

        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'dummy_password_hash')->status);
    }

    public function test_production_fails_when_bcrypt_rounds_is_present_but_not_an_integer(): void
    {
        // The boot gate (configuredBcryptRounds) aborts when BCRYPT_ROUNDS is present
        // but not an integer. The doctor must fail too rather than silently fall back
        // to the default of 12 and then grade the dummy-hash cost (also 12) as a match
        // -- passing a config the production boot never gets past. Non-integer here,
        // not merely a different integer, is the drift SecurityInvariants forbids.
        foreach (['twelve', '12.5', '', '0x0c'] as $rounds) {
            $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
                'hashing.bcrypt.rounds' => $rounds,
            ]))))->run();

            $this->assertFalse($report->passed(), sprintf('Non-integer bcrypt rounds [%s] must fail the doctor.', $rounds));
            $this->assertSame(
                DoctorCheckStatus::Fail,
                $this->check($report->checks, 'dummy_password_hash')->status,
                sprintf('Non-integer bcrypt rounds [%s] must fail the dummy_password_hash check.', $rounds),
            );
        }
    }

    public function test_production_session_domain_may_cover_only_the_app_host(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'session.domain' => 'app.example.test',
        ]))))->run();

        $this->assertSame(DoctorCheckStatus::Pass, $this->check($report->checks, 'session_domain')->status);
    }

    public function test_production_reverb_misconfiguration_fails_and_non_reverb_is_skipped(): void
    {
        $misconfigured = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => 'change-me-please',
            'app.reverb_url' => 'https://elsewhere.example.test',
            'reverb.apps.apps.0.allowed_origins' => ['*'],
            'reverb.apps.apps.0.rate_limiting.enabled' => false,
            'reverb.apps.apps.0.max_connections' => null,
        ]))))->run();

        $this->assertSame(DoctorCheckStatus::Fail, $this->check($misconfigured->checks, 'reverb')->status);

        $withoutReverb = (new DeploymentDoctor($this->config('production', $this->hardenedProductionConfig())))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($withoutReverb->checks, 'reverb')->status);

        $hardenedReverb = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => 'base64:' . base64_encode(str_repeat('r', 32)),
            'app.reverb_url' => 'https://app.example.test',
            'reverb.apps.apps.0.allowed_origins' => ['app.example.test'],
            'reverb.apps.apps.0.rate_limiting.enabled' => true,
            'reverb.apps.apps.0.max_connections' => 1000,
        ]))))->run();

        $this->assertSame(DoctorCheckStatus::Pass, $this->check($hardenedReverb->checks, 'reverb')->status);
        $this->assertTrue($hardenedReverb->passed(), $this->describeFailures($hardenedReverb->checks));
    }

    public function test_production_fails_when_the_signing_key_is_a_repository_published_fixture(): void
    {
        // The boot gate (ensureDedicatedSigningKey) rejects the published e2e signing
        // key even though it is a valid 32-byte non-placeholder string that isStrong()
        // blesses. The doctor must fail it too, or it grades green on a signing key the
        // production boot then aborts on -- the exact drift SecurityInvariants forbids.
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'app.artifact_url_signing_key' => 'artifact-preview-test-signing-key',
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'signing_key')->status);
    }

    public function test_production_fails_when_a_configured_reverb_origin_scheme_mismatches_the_app_origin(): void
    {
        // A configured_allowed_origins entry that carries a scheme must match the app
        // origin in full (scheme and port), not just the host. An http:// entry against
        // an https app origin shares the host but is a different browser origin; the
        // boot gate rejects it, so the doctor must fail rather than pass on the host.
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.secret' => 'base64:' . base64_encode(str_repeat('r', 32)),
            'app.reverb_url' => 'https://app.example.test',
            'reverb.apps.apps.0.allowed_origins' => ['app.example.test'],
            'reverb.apps.apps.0.configured_allowed_origins' => ['http://app.example.test'],
            'reverb.apps.apps.0.rate_limiting.enabled' => true,
            'reverb.apps.apps.0.max_connections' => 1000,
        ]))))->run();

        $this->assertFalse($report->passed());
        $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'reverb')->status);
    }

    public function test_doctor_fails_when_html_writes_exceed_the_production_http_request_envelope(): void
    {
        $report = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'pages.max_html_bytes' => 5 * 1024 * 1024 + 1,
            'pages.artifact_max_bytes' => 10 * 1024 * 1024,
        ]))))->run();

        $check = $this->check($report->checks, 'artifact_limits');
        $this->assertSame(DoctorCheckStatus::Fail, $check->status);
        $this->assertStringContainsString('production HTTP request envelope', $check->detail);
    }

    /**
     * Keep the doctor's punch list in correspondence with the invariants
     * ProductionSecurityConfiguration::ensureSafe() enforces at boot. Checked in
     * both directions: every listed id must be reported by the doctor, and every
     * ensure* invariant on the boot gate must be accounted for below (reflection),
     * so a new boot-gate invariant fails this test until a matching doctor check is
     * added. The doctor must never silently under-report.
     */
    public function test_doctor_covers_every_boot_gate_invariant(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [])))->run();
        $ids = array_map(static fn (DoctorCheck $check): string => $check->id, $report->checks);

        $expected = [
            'runtime_role',        // ensureRuntimeRole
            'origins_distinct',    // distinct app/artifact origins
            'origins_pure',        // production origins must be bare origins
            'app_key',             // ensureApplicationKey
            'signing_key',         // ensureDedicatedSigningKey
            'frame_ancestors',     // ensureArtifactFrameAncestors
            'artifact_limits',     // ensureArtifactReadLimitCanServeHtmlWrites
            'https_origins',       // productionOrigin HTTPS requirement
            'database_driver',     // ensureDatabaseDriver
            'database_tls',        // ensureDatabaseTls
            'database_password',   // ensureDatabasePassword
            'mail_transport',      // ensureMailTransportDoesNotLogSecrets
            'invitation_queue',    // ensureTransactionalInvitationQueue
            'secure_sessions',     // ensureSecureSessions
            'trusted_proxies',     // ensureTrustedProxies
            'artifact_storage',    // artifacts disk private
            'cache_store',         // ensureSharedRateLimiterCacheStore
            'bootstrap_passwords', // no persistent bootstrap passwords
            'debug_disabled',      // ensureDebugDisabled
            'dummy_password_hash', // ensureDummyPasswordHashCost
            'session_domain',      // ensureSessionDomainDoesNotCoverArtifactHost
            'reverb',              // ensureReverbConfiguration
            'bootstrap_command',   // bootstrap admin path required
        ];

        foreach ($expected as $id) {
            $this->assertContains($id, $ids, sprintf('Doctor no longer reports the [%s] check.', $id));
        }

        // Reverse direction: pin the boot gate's private ensure* invariant methods
        // so adding a new one fails here. The id list above is hand-maintained, so a
        // one-way subset check would let a new boot-gate invariant ship with no
        // doctor check. (The public ensureSafe() orchestrator is excluded by the
        // IS_PRIVATE filter.)
        $ensureMethods = array_values(array_filter(
            array_map(
                static fn (ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass(ProductionSecurityConfiguration::class))->getMethods(ReflectionMethod::IS_PRIVATE),
            ),
            static fn (string $name): bool => str_starts_with($name, 'ensure'),
        ));
        sort($ensureMethods);

        $this->assertSame(
            [
                'ensureApplicationKey',
                'ensureArtifactFrameAncestors',
                'ensureArtifactReadLimitCanServeHtmlWrites',
                'ensureDatabaseDriver',
                'ensureDatabasePassword',
                'ensureDatabaseTls',
                'ensureDebugDisabled',
                'ensureDedicatedSigningKey',
                'ensureDummyPasswordHashCost',
                'ensureMailTransportIsDeliverable',
                'ensureReverbConfiguration',
                'ensureReverbMaxConnectionsBounded',
                'ensureRuntimeRole',
                'ensureSecureSessions',
                'ensureSessionDomainDoesNotCoverArtifactHost',
                'ensureSharedRateLimiterCacheStore',
                'ensureTransactionalInvitationQueue',
                'ensureTrustedProxies',
            ],
            $ensureMethods,
            'ProductionSecurityConfiguration gained or lost an ensure* invariant. Add or remove the matching DeploymentDoctor check and update both lists so doctor/boot-gate parity stays enforced.',
        );
    }

    public function test_non_delivering_or_unknown_mail_transport_fails_in_production_and_is_skipped_locally(): void
    {
        // 'log' leaks token URLs, 'array' silently drops mail, 'stmp' is a typo that is
        // not a configured mailer -- the doctor must fail each just like the boot gate,
        // not pass anything that is not a real, deliverable transport.
        foreach (['log', 'array', 'stmp'] as $mailer) {
            $report = (new DeploymentDoctor($this->config('production', ['mail.default' => $mailer])))->run();
            $this->assertSame(
                DoctorCheckStatus::Fail,
                $this->check($report->checks, 'mail_transport')->status,
                sprintf('Mailer [%s] must fail the doctor in production.', $mailer),
            );
        }

        $productionReal = (new DeploymentDoctor($this->config('production', ['mail.default' => 'smtp'])))->run();
        $this->assertSame(DoctorCheckStatus::Pass, $this->check($productionReal->checks, 'mail_transport')->status);

        $local = (new DeploymentDoctor($this->config('local', ['mail.default' => 'log'])))->run();
        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($local->checks, 'mail_transport')->status);
    }

    public function test_invitation_queue_must_share_the_primary_database_transaction_in_production(): void
    {
        foreach ([
            ['queue.default' => 'sync'],
            ['queue.connections.database.connection' => 'queue_database'],
            ['queue.connections.database.after_commit' => true],
        ] as $override) {
            $report = (new DeploymentDoctor($this->config('production', array_merge(
                $this->hardenedProductionConfig(),
                $override,
            ))))->run();

            $this->assertSame(DoctorCheckStatus::Fail, $this->check($report->checks, 'invitation_queue')->status);
        }

        $local = (new DeploymentDoctor($this->config('local', ['queue.default' => 'sync'])))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($local->checks, 'invitation_queue')->status);
    }

    public function test_host_ports_pass_when_docker_mappings_match_the_origin_urls(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [
            'app.host_port' => '18080',
            'app.artifact_host_port' => '18081',
        ])))->run();

        $this->assertSame(DoctorCheckStatus::Pass, $this->check($report->checks, 'host_ports')->status);
        $this->assertTrue($report->passed(), $this->describeFailures($report->checks));
    }

    public function test_host_ports_warn_without_failing_when_a_mapping_and_its_url_disagree(): void
    {
        $appMismatch = (new DeploymentDoctor($this->config('local', [
            'app.host_port' => '28080',
        ])))->run();

        $check = $this->check($appMismatch->checks, 'host_ports');
        $this->assertSame(DoctorCheckStatus::Warn, $check->status);
        $this->assertStringContainsString('APP_URL', $check->detail);
        $this->assertTrue($appMismatch->passed(), 'A host-port mismatch is a usability warning, never a boot-gate failure.');

        $artifactMismatch = (new DeploymentDoctor($this->config('local', [
            'app.artifact_host_port' => '28081',
        ])))->run();

        $this->assertSame(DoctorCheckStatus::Warn, $this->check($artifactMismatch->checks, 'host_ports')->status);
    }

    public function test_host_ports_warn_on_values_that_are_not_valid_ports(): void
    {
        $report = (new DeploymentDoctor($this->config('local', [
            'app.host_port' => 'not-a-port',
        ])))->run();

        $this->assertSame(DoctorCheckStatus::Warn, $this->check($report->checks, 'host_ports')->status);

        $outOfRange = (new DeploymentDoctor($this->config('local', [
            'app.host_port' => '70000',
        ])))->run();

        $this->assertSame(DoctorCheckStatus::Warn, $this->check($outOfRange->checks, 'host_ports')->status);
    }

    public function test_host_ports_are_skipped_without_a_visible_mapping_and_in_production(): void
    {
        $unset = (new DeploymentDoctor($this->config('local', [])))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($unset->checks, 'host_ports')->status);

        $production = (new DeploymentDoctor($this->config('production', array_merge($this->hardenedProductionConfig(), [
            'app.host_port' => '18080',
        ]))))->run();

        $this->assertSame(DoctorCheckStatus::Skipped, $this->check($production->checks, 'host_ports')->status);
        $this->assertTrue($production->passed(), $this->describeFailures($production->checks));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(string $environment, array $overrides): Repository
    {
        $base = [
            'app.env' => $environment,
            'app.runtime_role' => 'app',
            'app.url' => 'http://localhost:18080',
            'app.artifact_url' => 'http://127.0.0.1:18081',
            'app.artifact_frame_ancestors' => 'http://localhost:18080',
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.artifact_url_signing_key' => 'base64:' . base64_encode(str_repeat('b', 32)),
            'app.bootstrap_admin_password' => '',
            'app.create_user_password' => '',
            'app.reset_user_password' => '',
            'cache.default' => 'database',
            'cache.stores.array.driver' => 'array',
            'cache.stores.database.driver' => 'database',
            'mail.default' => 'smtp',
            'mail.mailers' => [
                'smtp' => ['transport' => 'smtp'],
                'ses' => ['transport' => 'ses'],
                'log' => ['transport' => 'log'],
                'array' => ['transport' => 'array'],
            ],
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.after_commit' => false,
            'pages.artifact_max_bytes' => 2_000_000,
            'pages.max_html_bytes' => 1_000_000,
            'pages.max_markdown_bytes' => 1_000_000,
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'prefer',
            'database.connections.pgsql.sslrootcert' => '',
            'session.driver' => 'array',
            'session.secure' => false,
            'session.encrypt' => false,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.domain' => '',
            'trustedproxy.raw' => '172.16.0.0/12',
            'filesystems.disks.artifacts.visibility' => 'private',
            'filesystems.disks.artifacts.root' => storage_path('app/private_artifacts'),
            'app.debug' => true,
            'app.bootstrap_admin_command' => 'php artisan artifactflow:bootstrap-admin',
            'auth.dummy_password_hash' => '',
            'hashing.bcrypt.rounds' => 12,
            'broadcasting.default' => 'log',
        ];

        return new Repository(Arr::undot(array_merge($base, $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function hardenedProductionConfig(): array
    {
        return [
            'app.url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.artifact_frame_ancestors' => 'https://app.example.test',
            'database.connections.pgsql.password' => 'app-local-strong-password',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.sslrootcert' => '/etc/ssl/certs/db-ca.pem',
            'session.driver' => 'database',
            'session.secure' => true,
            'session.encrypt' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'trustedproxy.raw' => '10.0.0.1',
            'app.debug' => false,
            'auth.dummy_password_hash' => '$2y$12$xm0UA0D2OPiZ6/nnQh8xgejBhHl4A5jjwewkvxe9iCf7uZYBYxgBe',
        ];
    }

    /**
     * @param list<DoctorCheck> $checks
     */
    private function check(array $checks, string $id): DoctorCheck
    {
        foreach ($checks as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        $this->fail(sprintf('Doctor check [%s] was not produced.', $id));
    }

    /**
     * @param list<DoctorCheck> $checks
     */
    private function describeFailures(array $checks): string
    {
        $failed = array_filter($checks, static fn (DoctorCheck $check): bool => $check->isFailure());

        return implode('; ', array_map(
            static fn (DoctorCheck $check): string => sprintf('%s: %s', $check->id, $check->detail),
            $failed,
        ));
    }
}
