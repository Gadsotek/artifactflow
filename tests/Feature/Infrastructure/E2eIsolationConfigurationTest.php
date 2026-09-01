<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

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
        $e2eApp = $this->serviceBlock($compose, 'e2e-app', 'e2e-artifact-host');

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
        $this->assertStringContainsString('CACHE_STORE: database', $e2eApp);
        $this->assertStringContainsString(
            'EXTERNAL_SHARE_PUBLIC_IP_RATE_LIMIT_PER_MINUTE: ${E2E_EXTERNAL_SHARE_PUBLIC_IP_RATE_LIMIT_PER_MINUTE:-1000}',
            $compose,
        );
        $this->assertStringContainsString('e2e-artifacts:', $compose);
    }

    public function test_artifact_hosts_have_only_database_and_restricted_ingress_network_reachability(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $caddy = $this->readProjectFile('docker/Caddyfile.local');
        $makefile = $this->readProjectFile('Makefile');
        $runtimeProof = $this->readProjectFile('scripts/verify-runtime-network-isolation.sh');
        $artifactHost = $this->serviceBlock($compose, 'artifact-host', 'e2e-pdf-processor');
        $e2eArtifactHost = $this->serviceBlock($compose, 'e2e-artifact-host', 'e2e-edge');

        $this->assertStringContainsString("networks:\n      - artifact-runtime", $artifactHost);
        $this->assertStringNotContainsString('- default', $artifactHost);
        $this->assertStringContainsString("networks:\n      - e2e-artifact-runtime", $e2eArtifactHost);
        $this->assertStringNotContainsString('- default', $e2eArtifactHost);
        $this->assertStringNotContainsString('ports:', $artifactHost);
        $this->assertStringNotContainsString('ports:', $e2eArtifactHost);
        $this->assertStringContainsString('artifact-gateway:', $compose);
        $this->assertStringContainsString('e2e-artifact-gateway:', $compose);
        $this->assertStringContainsString(
            '"127.0.0.1:${E2E_ARTIFACT_HOST_PORT:-18181}:80"',
            $compose,
        );
        $gateway = $this->readProjectFile('docker/Caddyfile.artifact-gateway');
        $this->assertStringContainsString('reverse_proxy {$ARTIFACT_UPSTREAM}', $gateway);
        $this->assertStringNotContainsString('APP_UPSTREAM', $gateway);
        $this->assertStringContainsString('remote_ip {$ARTIFACT_RUNTIME_CIDR}', $caddy);
        $this->assertStringContainsString('respond @artifactRuntime 403', $caddy);
        $this->assertStringContainsString('verify-runtime-network-isolation:', $makefile);
        $this->assertStringContainsString('HostConfig.NetworkMode', $runtimeProof);
        $this->assertStringContainsString('e2e-edge', $runtimeProof);
    }

    public function test_e2e_uses_dedicated_networkless_processor_instances_and_socket_volumes(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $makefile = $this->readProjectFile('Makefile');
        $e2eApp = $this->serviceBlock($compose, 'e2e-app', 'e2e-artifact-host');
        $e2eParser = $this->serviceBlock($compose, 'e2e-image-parser', 'e2e-pdf-processor');

        $this->assertStringContainsString('E2E_IMAGE_PARSER_SERVICE ?= e2e-image-parser', $makefile);
        $this->assertStringContainsString('network_mode: none', $e2eParser);
        $this->assertStringContainsString('e2e-image-parser-socket:/run/artifactflow/image-parser', $e2eParser);
        $this->assertStringContainsString('e2e-image-parser:', $e2eApp);
        $this->assertStringNotContainsString("\n      image-parser:", $e2eApp);
        $this->assertStringContainsString(
            'e2e-image-parser-socket:/run/artifactflow/e2e-image-parser:ro',
            $e2eApp,
        );
        $this->assertStringContainsString('$(E2E_IMAGE_PARSER_SERVICE)', $makefile);
    }

    public function test_e2e_processor_health_checks_poll_quickly_only_during_startup(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $processorServices = [
            $this->serviceBlock($compose, 'e2e-image-parser', 'e2e-pdf-processor'),
            $this->serviceBlock($compose, 'e2e-pdf-processor', 'e2e-xlsx-processor'),
            $this->serviceBlock($compose, 'e2e-xlsx-processor', 'e2e-docx-processor'),
            $this->serviceBlock($compose, 'e2e-docx-processor', 'e2e-app'),
        ];

        foreach ($processorServices as $service) {
            $this->assertStringContainsString("interval: 1m", $service);
            $this->assertStringContainsString("start_interval: 5s", $service);
            $this->assertStringNotContainsString("\n      interval: 5s\n", $service);
        }
    }

    public function test_php_test_harness_does_not_inherit_real_processor_socket_endpoints(): void
    {
        $testCase = $this->readProjectFile('tests/TestCase.php');

        $this->assertStringContainsString("'image_parser.socket_path' => null", $testCase);
        $this->assertStringContainsString("'pdf_processor.socket_path' => null", $testCase);
        $this->assertStringContainsString("'xlsx_processor.socket_path' => null", $testCase);
        $this->assertStringContainsString("'docx_processor.socket_path' => null", $testCase);
    }

    public function test_e2e_runs_the_real_turnstile_widget_with_public_test_credentials(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $startup = $this->readProjectFile('docker/start-e2e-app.sh');
        $spec = $this->readProjectFile('tests/e2e/turnstile.spec.ts');

        $this->assertStringContainsString(
            "test('Turnstile widgets render on the real authentication pages under their CSP'",
            $spec,
        );
        $this->assertStringContainsString(
            "const turnstileTestSiteKey = '1x00000000000000000000AA';",
            $spec,
        );
        $this->assertStringContainsString(
            "process.env.E2E_TURNSTILE_APP_PORT ?? '18182'",
            $spec,
        );
        $this->assertStringNotContainsString("createServer } from 'node:http'", $spec);
        $this->assertStringNotContainsString('test.skip(', $spec);
        $this->assertStringContainsString(
            'TURNSTILE_SITE_KEY="1x00000000000000000000AA"',
            $startup,
        );
        $this->assertStringContainsString(
            'TURNSTILE_SECRET_KEY="1x0000000000000000000000000000000AA"',
            $startup,
        );
        $this->assertStringContainsString(
            'turnstile_app_port="${E2E_TURNSTILE_APP_PORT:-18182}"',
            $startup,
        );
        $this->assertStringContainsString(
            'APP_URL="http://localhost:${turnstile_app_port}"',
            $startup,
        );
        $this->assertStringContainsString(
            'command: ["sh", "/var/www/html/docker/start-e2e-app.sh"]',
            $compose,
        );
        $this->assertStringContainsString(
            'E2E_TURNSTILE_APP_PORT: ${E2E_TURNSTILE_APP_PORT:-18182}',
            $compose,
        );
        $this->assertStringContainsString(
            '"127.0.0.1:${E2E_TURNSTILE_APP_PORT:-18182}:8001"',
            $compose,
        );

        $appMatched = preg_match(
            '/\n  e2e-app:(?<block>.*?)\n  e2e-artifact-host:/s',
            $compose,
            $appMatches,
        );
        $artifactHostMatched = preg_match(
            '/\n  e2e-artifact-host:(?<block>.*?)\nvolumes:/s',
            $compose,
            $artifactHostMatches,
        );
        $this->assertSame(1, $appMatched);
        $this->assertSame(1, $artifactHostMatched);
        $this->assertStringContainsString(
            '"/var/www/html/docker/healthcheck-app.sh && PORT=8001 /var/www/html/docker/healthcheck-app.sh"',
            $appMatches['block'],
        );
        foreach ([$appMatches['block'], $artifactHostMatches['block']] as $service) {
            $this->assertStringContainsString('TURNSTILE_SITE_KEY: ""', $service);
            $this->assertStringContainsString('TURNSTILE_SECRET_KEY: ""', $service);
        }
    }

    public function test_compose_does_not_shadow_the_mounted_environment_app_key(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $this->assertStringNotContainsString('APP_KEY: ${APP_KEY:-}', $compose);
    }

    public function test_reverb_uses_the_app_database_credential_except_for_the_connection_free_origin_probe(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        $this->assertStringContainsString(
            'DB_PASSWORD: ${REVERB_SMOKE_DB_PASSWORD:-${DB_PASSWORD:-app_local_password}}',
            $compose,
        );
        $this->assertStringNotContainsString(
            'DB_PASSWORD: ${REVERB_SMOKE_DB_PASSWORD:-${REVERB_APP_SECRET:-}}',
            $compose,
        );
    }

    public function test_reverb_worker_does_not_receive_image_parser_credentials(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $matched = preg_match('/\n  reverb:(?<block>.*?)\n  vite:/s', $compose, $matches);

        $this->assertSame(1, $matched);
        $reverb = $matches['block'];
        $this->assertStringContainsString('IMAGE_PARSER_URL: ""', $reverb);
        $this->assertStringContainsString('IMAGE_PARSER_SHARED_SECRET: ""', $reverb);
    }

    public function test_app_can_override_the_vite_hot_file_for_isolated_runtimes(): void
    {
        $config = $this->readProjectFile('config/app.php');
        $provider = $this->readProjectFile('app/Providers/AppServiceProvider.php');

        $this->assertStringContainsString("'vite_hot_file' => env('VITE_HOT_FILE')", $config);
        $this->assertStringContainsString('Vite::useHotFile($hotFile)', $provider);
    }

    public function test_local_artifact_host_ignores_the_app_vite_hot_file(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $artifactHost = $this->serviceBlock($compose, 'artifact-host', 'e2e-image-parser');

        $this->assertStringContainsString(
            'VITE_HOT_FILE: /tmp/artifactflow-artifact-host-no-hot',
            $artifactHost,
        );
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
            'E2E_COMPOSE ?= $(TEST_COMPOSE) --profile test --profile e2e',
            $makefile,
        );
        $this->assertStringContainsString(
            '$(E2E_COMPOSE) run --rm --no-deps $(E2E_APP_SERVICE)',
            $makefile,
        );
        $this->assertStringContainsString(
            '$(E2E_COMPOSE) up -d $(UP_BUILD) --force-recreate $(E2E_IMAGE_PARSER_SERVICE) $(E2E_PDF_PROCESSOR_SERVICE) $(E2E_XLSX_PROCESSOR_SERVICE) $(E2E_DOCX_PROCESSOR_SERVICE) $(E2E_APP_SERVICE) $(E2E_ARTIFACT_SERVICE) $(E2E_ARTIFACT_GATEWAY_SERVICE) $(E2E_EDGE_SERVICE)',
            $makefile,
        );
    }

    public function test_php_and_browser_test_wrappers_never_read_or_scaffold_the_developer_env_file(): void
    {
        $makefile = $this->readProjectFile('Makefile');
        $override = $this->readProjectFile('docker-compose.test.yml');
        $testRecipe = $this->makeTargetRecipe($makefile, 'test');
        $e2eRecipe = $this->makeTargetRecipe($makefile, 'e2e');

        $this->assertStringContainsString('ISOLATED_TEST_ENV_FILE ?= docker/e2e.env', $makefile);
        $this->assertStringContainsString('ISOLATED_TEST_COMPOSE_FILE ?= docker-compose.test.yml', $makefile);
        $this->assertStringContainsString(
            'TEST_COMPOSE ?= $(COMPOSE) --env-file $(ISOLATED_TEST_ENV_FILE) -f docker-compose.yml -f $(ISOLATED_TEST_COMPOSE_FILE)',
            $makefile,
        );
        $this->assertStringContainsString('E2E_COMPOSE ?= $(TEST_COMPOSE) --profile test --profile e2e', $makefile);
        $this->assertStringContainsString('$(MAKE) test-deps', $testRecipe);
        $this->assertStringNotContainsString('$(MAKE) deps', $testRecipe);
        $this->assertStringNotContainsString('ensure-env', $testRecipe);
        $this->assertStringNotContainsString('ensure-env', $e2eRecipe);
        $this->assertStringContainsString('$(TEST_COMPOSE)', $testRecipe);
        $this->assertStringContainsString('$(E2E_COMPOSE)', $e2eRecipe);

        foreach (['app', 'e2e-app', 'e2e-artifact-host'] as $service) {
            $this->assertMatchesRegularExpression(
                sprintf(
                    '/^  %s:.*?^      - \.\/docker\/e2e\.env:\/var\/www\/html\/\.env:ro$/ms',
                    preg_quote($service, '/'),
                ),
                $override,
            );
        }
    }

    public function test_e2e_explicitly_owns_both_office_processor_lifecycles(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString(
            'E2E_XLSX_PROCESSOR_SERVICE ?= e2e-xlsx-processor',
            $makefile,
        );
        $this->assertStringContainsString(
            'E2E_DOCX_PROCESSOR_SERVICE ?= e2e-docx-processor',
            $makefile,
        );
        $this->assertStringContainsString(
            '$(MAKE) wait APP_SERVICE=$(E2E_XLSX_PROCESSOR_SERVICE)',
            $makefile,
        );
        $this->assertStringContainsString(
            '$(MAKE) wait APP_SERVICE=$(E2E_DOCX_PROCESSOR_SERVICE)',
            $makefile,
        );

        $this->assertStringContainsString(
            'stop $(E2E_EDGE_SERVICE) $(E2E_ARTIFACT_GATEWAY_SERVICE) $(E2E_APP_SERVICE) $(E2E_ARTIFACT_SERVICE) $(E2E_IMAGE_PARSER_SERVICE) $(E2E_PDF_PROCESSOR_SERVICE) $(E2E_XLSX_PROCESSOR_SERVICE) $(E2E_DOCX_PROCESSOR_SERVICE)',
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

    public function test_artifact_parser_corpus_keeps_nested_make_diagnostics_out_of_json(): void
    {
        $spec = $this->readProjectFile('tests/e2e/artifact-parser-differential-fuzz.spec.ts');

        $this->assertMatchesRegularExpression(
            "/execFileSync\\(\\s*'make',\\s*\\[\\s*'--no-print-directory',\\s*appCommandTarget,/u",
            $spec,
        );
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

    private function serviceBlock(string $compose, string $service, string $nextService): string
    {
        $matched = preg_match(
            sprintf('/\n  %s:(?<block>.*?)\n  %s:/s', preg_quote($service, '/'), preg_quote($nextService, '/')),
            $compose,
            $matches,
        );
        $this->assertSame(1, $matched, sprintf('Expected Compose service [%s].', $service));

        return $matches['block'];
    }

    private function makeTargetRecipe(string $makefile, string $target): string
    {
        $matched = preg_match(
            sprintf('/^%s:\\s*\\n(?<recipe>(?:\\t.*\\n)+)/m', preg_quote($target, '/')),
            $makefile,
            $matches,
        );
        $this->assertSame(1, $matched, sprintf('Make target [%s] was not found.', $target));

        return $matches['recipe'];
    }
}
