<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class CiCoverageGateConfigurationTest extends TestCase
{
    public function test_makefile_exposes_type_and_line_coverage_through_the_isolated_test_wrapper(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString('COVERAGE_MIN ?= 94', $makefile);
        $this->assertStringContainsString('type-coverage:', $makefile);
        $this->assertStringContainsString('$(MAKE) test TYPE_COVERAGE=1', $makefile);
        $this->assertStringContainsString('coverage:', $makefile);
        $this->assertStringContainsString('$(MAKE) test COVERAGE=1 COVERAGE_MIN=$(COVERAGE_MIN)', $makefile);
        $this->assertStringContainsString('TYPE_COVERAGE_MIN ?= 100', $makefile);
        $this->assertStringContainsString('TYPE_COVERAGE_REPORT ?= storage/framework/testing/type-coverage.json', $makefile);
        $this->assertStringContainsString('--type-coverage --min=$(TYPE_COVERAGE_MIN)', $makefile);
        $this->assertStringContainsString('--type-coverage-json=$(TYPE_COVERAGE_JSON)', $makefile);
        $this->assertStringContainsString("run-app-cmd APP_CMD='php scripts/type-coverage-guard.php", $makefile);
        $this->assertStringContainsString('pcov.enabled=$(if $(COVERAGE),1,0)', $makefile);
        $this->assertStringContainsString('XDEBUG_MODE=off', $makefile);
        $this->assertStringNotContainsString('XDEBUG_MODE=$(if $(COVERAGE),coverage,off)', $makefile);
    }

    public function test_make_up_runs_the_artifact_signing_key_provisioner_script_directly(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString("ensure-artifact-signing-key: ensure-env\n\t@if command -v php >/dev/null 2>&1; then", $makefile);
        $this->assertStringContainsString('php scripts/ensure-artifact-signing-key.php', $makefile);
        $this->assertStringContainsString('sh scripts/ensure-artifact-signing-key.sh', $makefile);
        $this->assertStringNotContainsString('$(MAKE) scripts/ensure-artifact-signing-key.php', $makefile);
        $this->assertStringNotContainsString('generate-singing-key:', $makefile);
    }

    public function test_dev_image_installs_pcov_without_leaking_it_into_production_build_stages(): void
    {
        $dockerfile = $this->readProjectFile('Dockerfile');
        $pcovIni = $this->readProjectFile('docker/php/conf.d/95-pcov.ini');
        $compose = $this->readProjectFile('docker-compose.yml');
        $workflow = $this->readProjectFile('.github/workflows/ci.yml');

        $this->assertStringContainsString('ARG INSTALL_PCOV=0', $dockerfile);
        $this->assertStringContainsString('pecl install pcov', $dockerfile);
        $this->assertStringContainsString('95-pcov.ini', $dockerfile);
        $this->assertStringContainsString('INSTALL_PCOV: ${INSTALL_PCOV:-1}', $compose);
        $this->assertStringContainsString('INSTALL_PCOV=1', $workflow);
        $this->assertStringContainsString('php -m | grep -qi pcov', $workflow);
        $this->assertStringContainsString('! php -m | grep -qi pcov', $workflow);

        $productionStage = $this->afterNeedle($dockerfile, 'FROM dunglas/frankenphp:1-php8.5-alpine@sha256:');
        $this->assertStringNotContainsString('pcov', strtolower($productionStage));

        $this->assertStringContainsString('pcov.enabled=1', $pcovIni);
        $this->assertStringContainsString('pcov.directory=/var/www/html/app', $pcovIni);
    }

    public function test_js_lint_and_format_tooling_is_configured(): void
    {
        $packageJson = $this->readProjectFile('package.json');
        $eslintConfig = $this->readProjectFile('eslint.config.js');
        $prettierrc = $this->readProjectFile('.prettierrc.json');

        // npm scripts and dev dependencies that back `make lint-js`.
        $this->assertStringContainsString('"lint:js"', $packageJson);
        $this->assertStringContainsString('"format:check"', $packageJson);
        $this->assertStringContainsString('"eslint"', $packageJson);
        $this->assertStringContainsString('"prettier"', $packageJson);

        // ESLint lints the browser modules and defers formatting to Prettier.
        $this->assertStringContainsString('resources/js', $eslintConfig);
        $this->assertStringContainsString('eslint-config-prettier', $eslintConfig);

        // Prettier is pinned to the repo's existing style (2-space, single quote).
        $this->assertStringContainsString('"singleQuote": true', $prettierrc);
        $this->assertStringContainsString('"tabWidth": 2', $prettierrc);
    }

    public function test_ci_avoids_a_redundant_plain_suite_and_required_docs_list_both_coverage_targets(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/ci.yml');
        $agents = $this->readProjectFile('AGENTS.md');
        $readme = $this->readProjectFile('README.md');

        $this->assertStringContainsString('name: PHP test and coverage gates', $workflow);
        $this->assertStringContainsString('make type-coverage', $workflow);
        $this->assertStringContainsString('make coverage', $workflow);
        $this->assertStringNotContainsString('name: Test suite', $workflow);
        $this->assertStringNotContainsString('name: Type coverage', $workflow);
        $this->assertStringNotContainsString('name: Line coverage', $workflow);

        $this->assertStringContainsString('make type-coverage', $agents);
        $this->assertStringContainsString('make coverage', $agents);
        $this->assertStringContainsString('100% type coverage', $agents);
        $this->assertStringContainsString('PCOV', $readme);
        $this->assertStringContainsString('make type-coverage', $readme);
        $this->assertStringContainsString('make coverage', $readme);
    }

    public function test_capability_verifier_fuzz_corpus_has_a_focused_target_without_duplicate_ci_execution(): void
    {
        $makefile = $this->readProjectFile('Makefile');
        $workflow = $this->readProjectFile('.github/workflows/ci.yml');

        $this->assertStringContainsString('fuzz-capabilities:', $makefile);
        $this->assertStringContainsString(
            '$(MAKE) test TEST_FILTER=ArtifactDraftPreviewCapabilitiesFuzzTest',
            $makefile,
        );
        $this->assertStringNotContainsString('name: Capability verifier fuzz corpus', $workflow);
        $this->assertStringNotContainsString('run: make fuzz-capabilities', $workflow);
    }

    public function test_release_security_gates_include_general_sast_trivy_secret_misconfig_and_moderate_npm_audit(): void
    {
        $makefile = $this->readProjectFile('Makefile');
        $workflow = $this->readProjectFile('.github/workflows/ci.yml');
        $nightly = $this->readProjectFile('.github/workflows/nightly-audit.yml');
        $dockerfile = $this->readProjectFile('Dockerfile');
        $healthcheck = $this->readProjectFile('docker/healthcheck-app.sh');

        $this->assertStringContainsString('--config p/php', $makefile);
        $this->assertStringContainsString('--config p/security-audit', $makefile);
        $this->assertStringContainsString('--scanners vuln,secret,misconfig', $makefile);
        $this->assertStringContainsString('fs $(TRIVY_REPO_SCAN_SKIP_DIRS) --scanners secret,misconfig --severity MEDIUM,HIGH,CRITICAL --exit-code 1 /src', $makefile);
        $this->assertStringNotContainsString('trivy" config $(TRIVY_REPO_SCAN_SKIP_DIRS)', $makefile);
        $this->assertStringContainsString('--skip-dirs /src/vendor', $makefile);
        $this->assertStringContainsString('--skip-dirs /src/node_modules', $makefile);
        $this->assertStringContainsString('npm audit --audit-level=moderate', $makefile);
        $this->assertStringContainsString('npm audit --audit-level=moderate', $workflow);
        $this->assertStringContainsString('npm audit --audit-level=moderate', $nightly);
        $this->assertStringContainsString('TRIVY_IMAGE ?= aquasec/trivy:0.72.0@sha256:', $makefile);
        $this->assertStringContainsString('FROM php:8.5.8-cli-trixie@sha256:', $dockerfile);
        $this->assertStringContainsString('FROM node:26-alpine@sha256:', $dockerfile);
        $this->assertStringContainsString('FROM dunglas/frankenphp:1-php8.5-alpine@sha256:', $dockerfile);
        $this->assertStringContainsString('COPY --from=composer:2.9@sha256:', $dockerfile);
        $this->assertStringNotContainsString('apk upgrade --no-cache', $dockerfile);
        $this->assertStringContainsString('/var/www/html/docker/healthcheck-app.sh', $dockerfile);
        $this->assertStringContainsString('worker|scheduler', $healthcheck);
        $workerHealthcheckPosition = strpos($healthcheck, 'worker|scheduler');
        $phpProbePosition = strpos($healthcheck, 'php -v >/dev/null');
        $this->assertNotFalse($workerHealthcheckPosition);
        $this->assertNotFalse($phpProbePosition);
        $this->assertLessThan(
            $phpProbePosition,
            $workerHealthcheckPosition,
            'worker and scheduler healthchecks must exit before running PHP bootstrap probes.',
        );

        // The worker/scheduler branch must do a real storage write probe, not a bare
        // exit 0, and that probe must live above the PHP bootstrap line.
        $this->assertStringContainsString('ARTIFACT_STORAGE_ROOT', $healthcheck);
        $this->assertStringContainsString('.healthcheck-${role}', $healthcheck);
        $probePosition = strpos($healthcheck, '.healthcheck-${role}');
        $this->assertNotFalse($probePosition);
        $this->assertLessThan(
            $phpProbePosition,
            $probePosition,
            'worker and scheduler storage write probe must run before the PHP bootstrap probes.',
        );

        // Compose must actually attach that script as the worker and scheduler
        // healthcheck; without a healthcheck stanza the probe never runs.
        $compose = $this->readProjectFile('docker-compose.yml');
        $workerBlock = substr($this->afterNeedle($compose, "\n  worker:"), 0, (int) strpos($this->afterNeedle($compose, "\n  worker:"), "\n  scheduler:"));
        $schedulerBlock = substr($this->afterNeedle($compose, "\n  scheduler:"), 0, (int) strpos($this->afterNeedle($compose, "\n  scheduler:"), "\n  reverb:"));
        $this->assertStringContainsString('healthcheck:', $workerBlock);
        $this->assertStringContainsString('/var/www/html/docker/healthcheck-app.sh', $workerBlock);
        $this->assertStringContainsString('healthcheck:', $schedulerBlock);
        $this->assertStringContainsString('/var/www/html/docker/healthcheck-app.sh', $schedulerBlock);
    }

    public function test_production_image_keeps_runtime_storage_out_of_the_build_context(): void
    {
        $dockerignore = $this->readProjectFile('.dockerignore');
        $dockerfile = $this->readProjectFile('Dockerfile');
        $filesystems = $this->readProjectFile('config/filesystems.php');
        $caddyfile = $this->readProjectFile('docker/Caddyfile');
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString('/storage/app/', $dockerignore);
        $this->assertStringNotContainsString('COPY storage ./storage', $dockerfile);
        $this->assertStringContainsString('COPY resources/js/artifact-preview-guard.js ./resources/js/artifact-preview-guard.js', $dockerfile);
        $this->assertStringContainsString('VOLUME ["/var/www/html/storage/app"]', $dockerfile);
        $this->assertStringContainsString('assert-prod-storage-empty:', $makefile);
        $this->assertStringContainsString('find /var/www/html/storage/app -type f -print -quit', $makefile);
        $this->assertStringContainsString('$(MAKE) assert-prod-storage-empty', $makefile);
        $this->assertStringContainsString("'serve' => false", $filesystems);
        $this->assertStringContainsString('@storage path /storage/* /storage', $caddyfile);
        $this->assertStringContainsString('respond 404', $caddyfile);
    }

    public function test_reverb_origin_handshake_probe_is_a_release_gate(): void
    {
        $makefile = $this->readProjectFile('Makefile');
        $compose = $this->readProjectFile('docker-compose.yml');
        $script = $this->readProjectFile('scripts/verify-reverb-origin-handshake.mjs');
        $operations = $this->readProjectFile('docs/OPERATIONS.md');

        $this->assertStringContainsString('verify-reverb-origin:', $makefile);
        $this->assertStringContainsString('$(COMPOSE) build app', $makefile);
        $this->assertStringContainsString('node scripts/verify-reverb-origin-handshake.mjs', $makefile);
        // The smoke target boots the worker in production mode, where the boot
        // gate rejects non-deliverable mail transports. The compose anchor
        // interpolates MAIL_MAILER from the developer's .env (log by default),
        // so the target must export a deliverable mailer itself or the worker
        // dies before the origin handshake is ever probed.
        $this->assertStringContainsString(
            'export MAIL_MAILER=smtp',
            $this->afterNeedle($makefile, 'verify-reverb-origin:'),
        );
        $this->assertStringContainsString(
            'CACHE_STORE: database',
            $this->afterNeedle($compose, 'reverb:'),
        );
        $this->assertStringContainsString('$(MAKE) verify-reverb-origin', $makefile);
        $this->assertStringContainsString('healthcheck:', $this->afterNeedle($compose, 'reverb:'));
        $this->assertStringContainsString('new WebSocket(websocketUrl(), { headers: { Origin: origin } })', $script);
        $this->assertStringContainsString('attempt <= 60', $script);
        $this->assertStringContainsString("message.event !== 'pusher:connection_established'", $script);
        $this->assertStringContainsString('data.code !== 4009', $script);
        $this->assertStringNotContainsString('readFrame(', $script);
        $this->assertStringContainsString('make verify-reverb-origin', $operations);
        $this->assertStringContainsString('Pusher error `4009`', $operations);
        $this->assertStringNotContainsString('foreign origin must receive `403 Forbidden`', $operations);
    }

    public function test_type_coverage_plugin_is_locked_as_a_dev_dependency(): void
    {
        $composerJson = json_decode($this->readProjectFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composerJson);

        $requireDev = $composerJson['require-dev'] ?? null;
        if (!is_array($requireDev)) {
            $this->fail('composer.json must define require-dev dependencies.');
        }

        $this->assertSame('^4.0', $requireDev['pestphp/pest-plugin-type-coverage'] ?? null);

        $composerLock = $this->readProjectFile('composer.lock');
        $this->assertStringContainsString('"name": "pestphp/pest-plugin-type-coverage"', $composerLock);
    }

    public function test_type_coverage_guard_fails_when_the_json_report_is_below_the_floor(): void
    {
        $belowFloorReport = $this->writeTypeCoverageReport(98.8);
        $passingReport = $this->writeTypeCoverageReport(100.0);

        try {
            $failed = $this->runTypeCoverageGuard($belowFloorReport, 100);
            $this->assertSame(1, $failed['exitCode']);
            $this->assertStringContainsString('below required 100.0%', $failed['output']);

            $passed = $this->runTypeCoverageGuard($passingReport, 100);
            $this->assertSame(0, $passed['exitCode']);
            $this->assertStringContainsString('meets required 100.0%', $passed['output']);
        } finally {
            @unlink($belowFloorReport);
            @unlink($passingReport);
        }
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function afterNeedle(string $contents, string $needle): string
    {
        $offset = strpos($contents, $needle);
        $this->assertIsInt($offset, sprintf('Expected to find "%s".', $needle));

        return substr($contents, $offset);
    }

    private function writeTypeCoverageReport(float $total): string
    {
        $path = tempnam(sys_get_temp_dir(), 'type-coverage-');
        $this->assertIsString($path);

        file_put_contents($path, json_encode(['total' => $total], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function runTypeCoverageGuard(string $reportPath, int $minimum): array
    {
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('scripts/type-coverage-guard.php')),
            escapeshellarg($reportPath),
            escapeshellarg((string) $minimum),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exitCode' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }
}
