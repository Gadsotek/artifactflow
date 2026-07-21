<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\Installation\InstallationReadiness;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Models\User;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class InstallationReadinessGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_routes_show_safe_installation_guidance_before_the_session_schema_exists(): void
    {
        config(['session.driver' => 'database']);
        Schema::drop('installation_settings');
        Schema::drop('sessions');

        $response = $this->get('/');

        $response
            ->assertStatus(503)
            ->assertSeeText('ArtifactFlow is not ready yet')
            ->assertSeeText('Guided first-time setup')
            ->assertSeeText('make install')
            ->assertSeeText('Apply the database schema only')
            ->assertSeeText('make migrate')
            ->assertSeeText('Create or promote a System Admin')
            ->assertSeeText('php artisan artifactflow:bootstrap-admin')
            ->assertHeader('Retry-After', '30')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_app_routes_are_blocked_while_a_database_migration_is_pending(): void
    {
        $latestMigration = DB::table('migrations')
            ->orderByDesc('migration')
            ->value('migration');
        $this->assertIsString($latestMigration);

        DB::table('migrations')->where('migration', $latestMigration)->delete();

        $this->get('/login')
            ->assertStatus(503)
            ->assertSeeText('Database setup or an upgrade is still pending.');
    }

    public function test_mcp_fails_closed_with_a_retryable_json_rpc_error_before_token_authentication(): void
    {
        $principal = User::factory()->create();
        $principal->forceFill(['is_service_account' => true])->save();
        $issuedToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $principal,
            name: 'Deployment readiness token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        );
        $this->assertNull($issuedToken->accessToken->last_used_at);

        $latestMigration = DB::table('migrations')
            ->orderByDesc('migration')
            ->value('migration');
        $this->assertIsString($latestMigration);
        DB::table('migrations')->where('migration', $latestMigration)->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $issuedToken->plainTextToken,
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'deployment-readiness',
            'method' => 'tools/list',
        ]);

        $response
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After', '30')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'jsonrpc' => '2.0',
                'id' => 'deployment-readiness',
                'error' => [
                    'code' => -32000,
                    'message' => 'ArtifactFlow is temporarily unavailable while database migrations are pending.',
                    'data' => [
                        'type' => 'installation_not_ready',
                        'retryable' => true,
                        'operator_action' => 'Run make migrate, or make install for a guided first-time setup.',
                    ],
                ],
            ]);

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($issuedToken->accessToken->refresh()->last_used_at);
    }

    public function test_a_long_running_process_rechecks_after_the_available_migration_manifest_changes(): void
    {
        /** @var MigrationRepositoryInterface&Mockery\MockInterface $repository */
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        /** @var Mockery\Expectation $getRanExpectation */
        $getRanExpectation = $repository->shouldReceive('getRan');
        $getRanExpectation->times(2)->andReturn(['0001_existing']);

        /** @var Migrator&Mockery\MockInterface $migrator */
        $migrator = Mockery::mock(Migrator::class);
        /** @var Mockery\Expectation $migrationFilesExpectation */
        $migrationFilesExpectation = $migrator->shouldReceive('getMigrationFiles');
        $migrationFilesExpectation
            ->times(2)
            ->with(database_path('migrations'))
            ->andReturn(
                ['0001_existing' => '/migrations/0001_existing.php'],
                [
                    '0001_existing' => '/migrations/0001_existing.php',
                    '0002_new' => '/migrations/0002_new.php',
                ],
            );
        /** @var Mockery\Expectation $repositoryExistsExpectation */
        $repositoryExistsExpectation = $migrator->shouldReceive('repositoryExists');
        $repositoryExistsExpectation->times(2)->andReturnTrue();
        /** @var Mockery\Expectation $getRepositoryExpectation */
        $getRepositoryExpectation = $migrator->shouldReceive('getRepository');
        $getRepositoryExpectation->times(2)->andReturn($repository);

        $readiness = new InstallationReadiness($migrator);

        $this->assertTrue($readiness->webSchemaIsReady());
        $this->assertFalse($readiness->webSchemaIsReady());
    }

    public function test_a_long_running_process_fails_closed_after_the_database_is_rolled_back(): void
    {
        /** @var MigrationRepositoryInterface&Mockery\MockInterface $repository */
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        /** @var Mockery\Expectation $getRanExpectation */
        $getRanExpectation = $repository->shouldReceive('getRan');
        $getRanExpectation
            ->times(2)
            ->andReturn(
                ['0001_existing', '0002_current'],
                ['0001_existing'],
            );

        /** @var Migrator&Mockery\MockInterface $migrator */
        $migrator = Mockery::mock(Migrator::class);
        /** @var Mockery\Expectation $migrationFilesExpectation */
        $migrationFilesExpectation = $migrator->shouldReceive('getMigrationFiles');
        $migrationFilesExpectation
            ->times(2)
            ->with(database_path('migrations'))
            ->andReturn([
                '0001_existing' => '/migrations/0001_existing.php',
                '0002_current' => '/migrations/0002_current.php',
            ]);
        /** @var Mockery\Expectation $repositoryExistsExpectation */
        $repositoryExistsExpectation = $migrator->shouldReceive('repositoryExists');
        $repositoryExistsExpectation->times(2)->andReturnTrue();
        /** @var Mockery\Expectation $getRepositoryExpectation */
        $getRepositoryExpectation = $migrator->shouldReceive('getRepository');
        $getRepositoryExpectation->times(2)->andReturn($repository);

        $readiness = new InstallationReadiness($migrator);

        $this->assertTrue($readiness->webSchemaIsReady());
        $this->assertFalse($readiness->webSchemaIsReady());
    }

    public function test_app_routes_remain_available_when_the_database_schema_is_current(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_health_endpoint_remains_available_before_installation(): void
    {
        config(['session.driver' => 'database']);
        Schema::drop('installation_settings');
        Schema::drop('sessions');

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_artifact_origin_does_not_disclose_application_installation_state(): void
    {
        config([
            'app.url' => 'https://app.example.internal',
            'app.artifact_url' => 'https://artifacts.example.internal',
            'app.runtime_role' => 'app',
            'session.driver' => 'database',
        ]);
        Schema::drop('sessions');

        $response = $this->get('https://artifacts.example.internal/');

        $response->assertNotFound()->assertDontSee('ArtifactFlow is not ready yet');
        $this->assertSame([], $response->headers->getCookies());
    }
}
