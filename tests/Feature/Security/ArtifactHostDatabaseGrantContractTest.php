<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ArtifactHostDatabaseGrantContractTest extends TestCase
{
    public function test_operations_ship_an_exact_least_privilege_artifact_host_grant_manifest(): void
    {
        $path = base_path('docs/operations/artifact-host-database-grants.sql');

        $this->assertFileExists($path);
        $sql = file_get_contents($path);
        $this->assertIsString($sql);

        foreach ([
            'pages',
            'page_versions',
            'external_shares',
            'external_share_sessions',
            'installation_settings',
        ] as $readTable) {
            $this->assertStringContainsString('GRANT SELECT ON TABLE public.' . $readTable, $sql);
        }

        $this->assertStringContainsString(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.artifact_rate_limit_cache',
            $sql,
        );
        $this->assertStringContainsString(
            'GRANT UPDATE (updated_at) ON TABLE public.pages, public.external_shares, public.external_share_sessions',
            $sql,
        );
        $this->assertStringContainsString(
            'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public',
            $sql,
        );
        $this->assertStringContainsString(
            'REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public',
            $sql,
        );
        $this->assertStringNotContainsString('public.cache TO artifactflow_artifact_host', $sql);
        $this->assertStringNotContainsString('public.cache_locks', $sql);
        $this->assertStringNotContainsString(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.rate_limit_cache TO artifactflow_artifact_host',
            $sql,
        );
        $this->assertStringNotContainsString('public.artifact_rate_limit_cache_locks', $sql);
        $this->assertStringNotContainsString('GRANT ALL', strtoupper($sql));

        $this->assertSame('database', config('cache.stores.database_limiter.driver'));
        $this->assertSame('rate_limit_cache', config('cache.stores.database_limiter.table'));
        $this->assertSame('database', config('cache.stores.database_artifact_limiter.driver'));
        $this->assertSame('artifact_rate_limit_cache', config('cache.stores.database_artifact_limiter.table'));
        $this->assertNotSame(
            config('cache.stores.database_limiter.table'),
            config('cache.stores.database_artifact_limiter.table'),
        );
        $productionEnvironment = file_get_contents(base_path('.env.production.example'));
        $this->assertIsString($productionEnvironment);
        $this->assertStringContainsString('CACHE_LIMITER=database_limiter', $productionEnvironment);
        $this->assertStringContainsString(
            'ARTIFACT_CACHE_LIMITER=database_artifact_limiter',
            $productionEnvironment,
        );
    }

    public function test_artifact_runtime_selects_the_dedicated_limiter_store_at_boot(): void
    {
        $probe = new Process(
            [
                PHP_BINARY,
                '-r',
                'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $store = config("cache.limiter"); echo is_string($store) ? $store : "";',
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_RUNTIME_ROLE' => 'artifact-host',
                'CACHE_LIMITER' => 'database_limiter',
                'ARTIFACT_CACHE_LIMITER' => 'database_artifact_limiter',
            ],
            null,
            10,
        );

        $probe->run();

        $this->assertTrue($probe->isSuccessful(), $probe->getErrorOutput());
        $this->assertSame('database_artifact_limiter', $probe->getOutput());
    }
}
