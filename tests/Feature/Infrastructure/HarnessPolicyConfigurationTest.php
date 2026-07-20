<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class HarnessPolicyConfigurationTest extends TestCase
{
    public function test_custom_semgrep_rules_are_tested_before_the_repository_scan(): void
    {
        $workflow = $this->projectFile('.github/workflows/ci.yml');
        $semgrepStep = $this->after($workflow, "- name: Semgrep\n");

        $this->assertFileExists(base_path('.semgrep/artifactflow.php'));
        $this->assertStringContainsString(
            'semgrep --test --config .semgrep/artifactflow.yml .semgrep/artifactflow.php',
            $semgrepStep,
        );
        $this->assertLessThan(
            strpos($semgrepStep, 'make semgrep'),
            strpos($semgrepStep, 'semgrep --test'),
        );
    }

    public function test_dockerfile_and_compose_pin_the_same_node_image(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');
        $compose = $this->projectFile('docker-compose.yml');

        preg_match('/FROM (node:26-alpine@sha256:[a-f0-9]{64}) AS frontend-build/', $dockerfile, $dockerMatch);
        preg_match('/image: (node:26-alpine@sha256:[a-f0-9]{64})/', $compose, $composeMatch);

        $this->assertArrayHasKey(1, $dockerMatch);
        $this->assertArrayHasKey(1, $composeMatch);
        $dockerImage = $dockerMatch[1] ?? null;
        $composeImage = $composeMatch[1] ?? null;
        $this->assertIsString($dockerImage);
        $this->assertIsString($composeImage);
        $this->assertSame($dockerImage, $composeImage);
    }

    public function test_ci_uses_one_tested_dco_validator(): void
    {
        $workflow = $this->projectFile('.github/workflows/ci.yml');

        $this->assertFileExists(base_path('scripts/validate-dco.sh'));
        $this->assertStringContainsString('sh scripts/validate-dco.sh "$BASE_SHA" "$HEAD_SHA"', $workflow);
        $this->assertStringNotContainsString("grep -qiE '^Signed-off-by:", $workflow);
    }

    public function test_ci_removes_the_redundant_plain_suite_while_preserving_both_coverage_gates(): void
    {
        $makefile = $this->projectFile('Makefile');
        $workflow = $this->projectFile('.github/workflows/ci.yml');

        $this->assertStringContainsString('type-coverage:', $makefile);
        $this->assertStringContainsString('coverage:', $makefile);
        $this->assertStringContainsString('name: PHP test and coverage gates', $workflow);
        $this->assertStringContainsString('make type-coverage', $workflow);
        $this->assertStringContainsString('make coverage', $workflow);
        $this->assertStringNotContainsString('name: Test suite', $workflow);
        $this->assertStringNotContainsString('name: Type coverage', $workflow);
        $this->assertStringNotContainsString('name: Line coverage', $workflow);
    }

    public function test_rector_is_a_required_zero_diff_gate(): void
    {
        $composer = json_decode($this->projectFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);
        $requireDev = $composer['require-dev'] ?? null;
        $this->assertIsArray($requireDev);

        $this->assertArrayHasKey('rector/rector', $requireDev);
        $this->assertArrayHasKey('driftingly/rector-laravel', $requireDev);
        $this->assertFileExists(base_path('rector.php'));

        $composerScripts = $composer['scripts'] ?? null;
        $this->assertIsArray($composerScripts);
        $this->assertArrayHasKey('rector', $composerScripts);

        $workflow = $this->projectFile('.github/workflows/ci.yml');
        $this->assertStringContainsString('name: Rector (dry-run)', $workflow);
        $this->assertStringContainsString("APP_CMD='composer rector'", $workflow);
    }

    public function test_ai_harness_carries_a_versioned_shared_file_contract(): void
    {
        $contract = json_decode(
            $this->projectFile('harness/ai-hooks-contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($contract);
        $this->assertSame(1, $contract['contract_version'] ?? null);
        $this->assertSame('artifactflow', $contract['canonical_project'] ?? null);
        $this->assertNotSame([], $contract['shared_files'] ?? []);
        $this->assertNotSame([], $contract['normalized_files'] ?? []);
        $this->assertFileExists(base_path('tests/Feature/Architecture/AiHarnessDriftContractTest.php'));
    }

    private function projectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function after(string $source, string $needle): string
    {
        $position = strpos($source, $needle);
        $this->assertIsInt($position, sprintf('Expected to find %s.', $needle));

        return substr($source, $position + strlen($needle));
    }
}
