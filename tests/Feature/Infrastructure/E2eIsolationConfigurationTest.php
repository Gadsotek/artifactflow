<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Security\SecurityInvariants;
use Tests\TestCase;

final class E2eIsolationConfigurationTest extends TestCase
{
    public function test_make_e2e_uses_an_isolated_database_and_application_stack(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString('E2E_DB_NAME ?= $(TEST_DB_DATABASE)_e2e_$(TEST_DB_RUN_ID)', $makefile);
        $this->assertStringContainsString('E2E_LOCK_DIR ?= storage/framework/testing/e2e.lock', $makefile);
        $this->assertStringContainsString('if ! mkdir "$$lock_dir" 2>/dev/null; then', $makefile);
        $this->assertStringContainsString('rmdir "$$lock_dir"', $makefile);
        $this->assertStringContainsString('test-db-create TEST_DB_NAME="$$db_name"', $makefile);
        $this->assertStringContainsString('test-db-drop TEST_DB_NAME="$$db_name"', $makefile);
        $this->assertStringContainsString('E2E_DB_DATABASE="$$db_name"', $makefile);
        $this->assertStringContainsString('E2E_APP_COMMAND_TARGET=run-e2e-app-cmd', $makefile);
        $this->assertStringNotContainsString(
            'PLAYWRIGHT_BASE_URL="$${PLAYWRIGHT_BASE_URL:-http://localhost:18080}" npx playwright test',
            $makefile,
        );
    }

    public function test_compose_defines_dedicated_e2e_app_services_backed_by_db_test(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $this->assertStringContainsString('e2e-app:', $compose);
        $this->assertStringContainsString('e2e-artifact-host:', $compose);
        $this->assertStringContainsString('profiles: ["e2e"]', $compose);
        $this->assertStringContainsString('DB_HOST: db-test', $compose);
        $this->assertStringContainsString('DB_DATABASE: ${E2E_DB_DATABASE:-artifactflow_test_e2e}', $compose);
        $this->assertStringContainsString(
            'ARTIFACT_STORAGE_ROOT: /var/www/html/storage/app/e2e_private_artifacts',
            $compose,
        );
        $this->assertStringContainsString('VITE_HOT_FILE: /tmp/artifactflow-e2e-no-hot', $compose);
        $this->assertStringContainsString('e2e-artifacts:', $compose);
    }

    public function test_compose_does_not_shadow_the_mounted_environment_app_key(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $this->assertStringNotContainsString('APP_KEY: ${APP_KEY:-}', $compose);
    }

    public function test_reverb_smoke_worker_db_password_satisfies_the_production_boot_gate(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        // verify-reverb-origin boots the reverb worker with APP_ENV=production, so the
        // audit boot gate rejects the app_local_password dev fixture the shared anchor
        // feeds from .env. A dedicated variable (unset everywhere) keeps .env's DB_PASSWORD
        // from shadowing the default; prove that default clears the gate's password checks.
        $matched = preg_match(
            '/DB_PASSWORD: \$\{REVERB_SMOKE_DB_PASSWORD:-(?<value>[^}]+)\}/',
            $compose,
            $matches,
        );

        $this->assertSame(1, $matched, 'Reverb service must set a dedicated smoke DB password.');
        $password = $matches['value'];
        $this->assertTrue(SecurityInvariants::databasePasswordIsAcceptable($password));
        $this->assertFalse(SecurityInvariants::databasePasswordIsPublishedFixture($password));
    }

    public function test_app_can_override_the_vite_hot_file_for_isolated_runtimes(): void
    {
        $config = $this->readProjectFile('config/app.php');
        $provider = $this->readProjectFile('app/Providers/AppServiceProvider.php');

        $this->assertStringContainsString("'vite_hot_file' => env('VITE_HOT_FILE')", $config);
        $this->assertStringContainsString('Vite::useHotFile($hotFile)', $provider);
    }

    public function test_e2e_container_creation_pins_compose_interpolation_to_the_committed_env_file(): void
    {
        // Compose interpolates ${VAR:-default} from the developer's .env by
        // default, so un-pinned anchor variables (SESSION_*, TRUSTED_PROXIES,
        // REVERB_*, ...) would leak local settings into the e2e services and
        // make browser tests behave differently per machine. The invocations
        // that CREATE e2e containers must therefore replace the default .env
        // lookup with the committed, comments-only guard file.
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString(
            '$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env run --rm --no-deps $(E2E_APP_SERVICE)',
            $makefile,
        );
        $this->assertStringContainsString(
            '$(COMPOSE) --profile test --profile e2e --env-file docker/e2e.env up -d $(UP_BUILD) --force-recreate $(E2E_APP_SERVICE) $(E2E_ARTIFACT_SERVICE)',
            $makefile,
        );
    }

    public function test_the_e2e_env_file_is_a_pure_interpolation_guard(): void
    {
        // Any assignment in this file would silently override compose-file
        // defaults for every e2e run; it must stay comments-only so the
        // compose defaults and Makefile-exported E2E_* variables remain the
        // single sources of truth.
        $lines = preg_split('/\R/', $this->readProjectFile('docker/e2e.env'));
        $this->assertIsArray($lines);

        $assignments = array_values(array_filter(
            $lines,
            static function (string $line): bool {
                $trimmed = trim($line);

                return $trimmed !== '' && !str_starts_with($trimmed, '#');
            },
        ));

        $this->assertSame([], $assignments, 'docker/e2e.env must contain only comments and blank lines.');
    }

    public function test_e2e_specs_route_setup_commands_to_the_e2e_app_service(): void
    {
        $spec = $this->readProjectFile('tests/e2e/saved-artifact-preview.spec.ts');

        $this->assertStringContainsString('E2E_APP_COMMAND_TARGET', $spec);
        $this->assertStringContainsString('run-e2e-app-cmd', $spec);
    }

    public function test_makefile_exposes_the_search_reindex_operator_command(): void
    {
        $makefile = $this->readProjectFile('Makefile');
        $operations = $this->readProjectFile('docs/OPERATIONS.md');

        $this->assertStringContainsString('reindex-search', $makefile);
        $this->assertStringContainsString("php artisan artifactflow:reindex-search $(REINDEX_ARGS)", $makefile);
        $this->assertStringContainsString('make reindex-search', $operations);
        $this->assertStringContainsString('REINDEX_ARGS=', $operations);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
