<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Infrastructure\Security\SecurityInvariants;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class SecurityInvariantsTest extends TestCase
{
    private SecurityInvariants $invariants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invariants = new SecurityInvariants(new Repository([]));
    }

    public function test_configured_bcrypt_rounds_reads_integers_numeric_strings_and_defaults(): void
    {
        $this->assertSame(13, $this->invariants(['hashing' => ['bcrypt' => ['rounds' => 13]]])->configuredBcryptRounds());
        $this->assertSame(14, $this->invariants(['hashing' => ['bcrypt' => ['rounds' => '14']]])->configuredBcryptRounds());
        $this->assertSame(12, $this->invariants->configuredBcryptRounds());
        $this->assertNull($this->invariants(['hashing' => ['bcrypt' => ['rounds' => ['nope']]]])->configuredBcryptRounds());
    }

    public function test_bcrypt_hash_cost_extracts_cost_only_from_bcrypt_hashes(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertSame(4, $this->invariants->bcryptHashCost($hash));
        $this->assertNull($this->invariants->bcryptHashCost('not-a-hash'));
        $this->assertNull($this->invariants->bcryptHashCost(''));
    }

    public function test_trusted_proxies_configured_and_wildcard_detection(): void
    {
        $this->assertFalse($this->invariants->trustedProxiesAreConfigured(''));
        $this->assertFalse($this->invariants->trustedProxiesAreConfigured('   '));
        $this->assertTrue($this->invariants->trustedProxiesAreConfigured('REMOTE_ADDR'));

        $this->assertTrue($this->invariants->trustedProxiesUseWildcard('*'));
        $this->assertTrue($this->invariants->trustedProxiesUseWildcard(' ** '));
        $this->assertFalse($this->invariants->trustedProxiesUseWildcard('203.0.113.7'));
    }

    public function test_trusted_proxies_reject_broad_docker_and_all_addresses_cidrs(): void
    {
        $this->assertTrue($this->invariants->trustedProxiesUseBroadDockerCidr('127.0.0.1, 172.16.0.0/12'));
        $this->assertFalse($this->invariants->trustedProxiesUseBroadDockerCidr('10.0.0.0/24'));

        foreach (['0.0.0.0/0', '::/0', '10.0.0.1, ::/0', '127.0.0.1,0.0.0.0/0'] as $trustEverything) {
            $this->assertTrue($this->invariants->trustedProxiesUseAllAddressesCidr($trustEverything), $trustEverything);
        }

        $this->assertFalse($this->invariants->trustedProxiesUseAllAddressesCidr('203.0.113.7,2001:db8::/32,10.0.0.0/24'));
    }

    public function test_postgres_tls_predicates(): void
    {
        $this->assertTrue($this->invariants->postgresSslModeIsVerifyFull('verify-full'));
        $this->assertTrue($this->invariants->postgresSslModeIsVerifyFull(' VERIFY-FULL '));
        $this->assertFalse($this->invariants->postgresSslModeIsVerifyFull('require'));
        $this->assertFalse($this->invariants->postgresSslModeIsVerifyFull(''));

        $fixture = sys_get_temp_dir() . '/artifactflow-root-cert-' . bin2hex(random_bytes(8));
        file_put_contents($fixture, 'test certificate fixture');

        try {
            $this->assertTrue($this->invariants->postgresRootCertIsReadable($fixture));
            $this->assertFalse($this->invariants->postgresRootCertIsReadable($fixture . '.missing'));
            $this->assertFalse($this->invariants->postgresRootCertIsReadable(sys_get_temp_dir()));
            $this->assertFalse($this->invariants->postgresRootCertIsReadable(''));
            $this->assertFalse($this->invariants->postgresRootCertIsReadable('   '));
        } finally {
            unlink($fixture);
        }
    }

    public function test_artifact_storage_root_must_resolve_outside_the_public_web_root(): void
    {
        $publicRoot = '/srv/artifactflow/public';

        $this->assertFalse($this->invariants->artifactStorageRootIsOutsidePublicPath(
            '/srv/artifactflow/public/artifacts',
            $publicRoot,
        ));
        $this->assertFalse($this->invariants->artifactStorageRootIsOutsidePublicPath($publicRoot, $publicRoot));
        $this->assertTrue($this->invariants->artifactStorageRootIsOutsidePublicPath(
            '/srv/artifactflow/storage/app/private_artifacts',
            $publicRoot,
        ));
        $this->assertTrue($this->invariants->artifactStorageRootIsOutsidePublicPath(
            '/srv/artifactflow/public-artifacts',
            $publicRoot,
        ));
    }

    public function test_artifact_storage_root_follows_existing_symlink_ancestors(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/artifactflow-path-' . bin2hex(random_bytes(8));
        $publicRoot = $fixtureRoot . '/public';
        $artifactLink = $fixtureRoot . '/artifact-link';

        $this->assertTrue(mkdir($publicRoot, 0o700, true));

        try {
            $this->assertTrue(symlink($publicRoot, $artifactLink));
            $this->assertFalse($this->invariants->artifactStorageRootIsOutsidePublicPath(
                $artifactLink . '/not-created-yet',
                $publicRoot,
            ));
        } finally {
            if (is_link($artifactLink)) {
                unlink($artifactLink);
            }

            rmdir($publicRoot);
            rmdir($fixtureRoot);
        }
    }

    public function test_signing_key_reuse_detects_current_and_retired_application_keys(): void
    {
        $signing = str_repeat('s', 32);

        $this->assertTrue($this->invariants->signingKeyReusesApplicationKey($signing, $signing, []));
        $this->assertTrue($this->invariants->signingKeyReusesApplicationKey($signing, 'other', [str_repeat('p', 32), $signing]));
        $this->assertFalse($this->invariants->signingKeyReusesApplicationKey($signing, 'other', [str_repeat('p', 32)]));
        // An empty application secret must not match a signing key.
        $this->assertFalse($this->invariants->signingKeyReusesApplicationKey($signing, '', []));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function invariants(array $config): SecurityInvariants
    {
        return new SecurityInvariants(new Repository($config));
    }
}
