<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Infrastructure\Security\SecurityInvariants;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class SecurityInvariantsTest extends TestCase
{
    public function test_configured_bcrypt_rounds_reads_integers_numeric_strings_and_defaults(): void
    {
        $this->assertSame(13, SecurityInvariants::configuredBcryptRounds(new Repository(['hashing' => ['bcrypt' => ['rounds' => 13]]])));
        $this->assertSame(14, SecurityInvariants::configuredBcryptRounds(new Repository(['hashing' => ['bcrypt' => ['rounds' => '14']]])));
        $this->assertSame(12, SecurityInvariants::configuredBcryptRounds(new Repository([])));
        $this->assertNull(SecurityInvariants::configuredBcryptRounds(new Repository(['hashing' => ['bcrypt' => ['rounds' => ['nope']]]])));
    }

    public function test_bcrypt_hash_cost_extracts_cost_only_from_bcrypt_hashes(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertSame(4, SecurityInvariants::bcryptHashCost($hash));
        $this->assertNull(SecurityInvariants::bcryptHashCost('not-a-hash'));
        $this->assertNull(SecurityInvariants::bcryptHashCost(''));
    }

    public function test_trusted_proxies_configured_and_wildcard_detection(): void
    {
        $this->assertFalse(SecurityInvariants::trustedProxiesAreConfigured(''));
        $this->assertFalse(SecurityInvariants::trustedProxiesAreConfigured('   '));
        $this->assertTrue(SecurityInvariants::trustedProxiesAreConfigured('REMOTE_ADDR'));

        $this->assertTrue(SecurityInvariants::trustedProxiesUseWildcard('*'));
        $this->assertTrue(SecurityInvariants::trustedProxiesUseWildcard(' ** '));
        $this->assertFalse(SecurityInvariants::trustedProxiesUseWildcard('203.0.113.7'));
    }

    public function test_trusted_proxies_reject_broad_docker_and_all_addresses_cidrs(): void
    {
        $this->assertTrue(SecurityInvariants::trustedProxiesUseBroadDockerCidr('127.0.0.1, 172.16.0.0/12'));
        $this->assertFalse(SecurityInvariants::trustedProxiesUseBroadDockerCidr('10.0.0.0/24'));

        foreach (['0.0.0.0/0', '::/0', '10.0.0.1, ::/0', '127.0.0.1,0.0.0.0/0'] as $trustEverything) {
            $this->assertTrue(SecurityInvariants::trustedProxiesUseAllAddressesCidr($trustEverything), $trustEverything);
        }

        $this->assertFalse(SecurityInvariants::trustedProxiesUseAllAddressesCidr('203.0.113.7,2001:db8::/32,10.0.0.0/24'));
    }

    public function test_postgres_tls_predicates(): void
    {
        $this->assertTrue(SecurityInvariants::postgresSslModeIsVerifyFull('verify-full'));
        $this->assertTrue(SecurityInvariants::postgresSslModeIsVerifyFull(' VERIFY-FULL '));
        $this->assertFalse(SecurityInvariants::postgresSslModeIsVerifyFull('require'));
        $this->assertFalse(SecurityInvariants::postgresSslModeIsVerifyFull(''));

        $this->assertTrue(SecurityInvariants::postgresRootCertIsConfigured('/etc/ssl/certs/ca.crt'));
        $this->assertFalse(SecurityInvariants::postgresRootCertIsConfigured(''));
        $this->assertFalse(SecurityInvariants::postgresRootCertIsConfigured('   '));
    }

    public function test_artifact_storage_root_must_resolve_outside_the_public_web_root(): void
    {
        $publicRoot = '/srv/artifactflow/public';

        $this->assertFalse(SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
            '/srv/artifactflow/public/artifacts',
            $publicRoot,
        ));
        $this->assertFalse(SecurityInvariants::artifactStorageRootIsOutsidePublicPath($publicRoot, $publicRoot));
        $this->assertTrue(SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
            '/srv/artifactflow/storage/app/private_artifacts',
            $publicRoot,
        ));
        $this->assertTrue(SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
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
            $this->assertFalse(SecurityInvariants::artifactStorageRootIsOutsidePublicPath(
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

        $this->assertTrue(SecurityInvariants::signingKeyReusesApplicationKey($signing, $signing, []));
        $this->assertTrue(SecurityInvariants::signingKeyReusesApplicationKey($signing, 'other', [str_repeat('p', 32), $signing]));
        $this->assertFalse(SecurityInvariants::signingKeyReusesApplicationKey($signing, 'other', [str_repeat('p', 32)]));
        // An empty application secret must not match a signing key.
        $this->assertFalse(SecurityInvariants::signingKeyReusesApplicationKey($signing, '', []));
    }
}
