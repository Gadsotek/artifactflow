<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

final class DependencyAutomationConfigurationTest extends TestCase
{
    public function test_native_dependency_updates_cover_php_and_javascript_before_nightly(): void
    {
        $dependabot = $this->projectFile('.github/dependabot.yml');

        $this->assertStringContainsString('package-ecosystem: composer', $dependabot);
        $this->assertSame(2, substr_count($dependabot, 'package-ecosystem: npm'));
        $this->assertStringContainsString('directory: /scripts/mcp-remote-bridge', $dependabot);
        $this->assertSame(3, substr_count($dependabot, 'interval: cron'));
        $this->assertStringContainsString("cronjob: '15 1 * * *'", $dependabot);
        $this->assertStringContainsString("cronjob: '30 1 * * *'", $dependabot);
        $this->assertStringContainsString("cronjob: '45 1 * * *'", $dependabot);
        $this->assertStringNotContainsString('package-ecosystem: docker', $dependabot);
    }

    public function test_renovate_updates_pinned_container_images_without_paid_tokens_or_automerge(): void
    {
        $config = json_decode($this->projectFile('renovate.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);

        $this->assertSame(['dockerfile', 'docker-compose', 'custom.regex'], $config['enabledManagers'] ?? null);
        $this->assertTrue($config['pinDigests'] ?? false);
        $this->assertFalse($config['automerge'] ?? true);
        $this->assertSame(
            'Signed-off-by: renovate[bot] <29139614+renovate[bot]@users.noreply.github.com>',
            $config['commitBody'] ?? null,
        );

        $rules = $config['packageRules'] ?? null;
        $this->assertIsArray($rules);
        $nodeRule = $rules[0] ?? null;
        $this->assertIsArray($nodeRule);
        $this->assertSame(['dockerfile', 'docker-compose'], $nodeRule['matchManagers'] ?? null);
        $this->assertSame(['node'], $nodeRule['matchPackageNames'] ?? null);
        $this->assertSame('node container image', $nodeRule['groupName'] ?? null);
    }

    public function test_renovate_extracts_the_scanner_tag_and_digest_from_the_shared_pin(): void
    {
        $config = json_decode($this->projectFile('renovate.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);
        $managers = $config['customManagers'] ?? null;
        $this->assertIsArray($managers);
        $manager = $managers[0] ?? null;
        $this->assertIsArray($manager);
        $this->assertSame('regex', $manager['customType'] ?? null);
        $this->assertSame(['docker/trivy-image.txt'], $manager['managerFilePatterns'] ?? null);
        $this->assertSame('docker', $manager['datasourceTemplate'] ?? null);
        $this->assertSame('docker', $manager['versioningTemplate'] ?? null);
        $patterns = $manager['matchStrings'] ?? null;
        $this->assertIsArray($patterns);
        $pattern = $patterns[0] ?? null;
        $this->assertIsString($pattern);

        $pin = trim($this->projectFile('docker/trivy-image.txt'));
        $this->assertSame(1, preg_match('~' . $pattern . '~', $pin, $matches));
        $this->assertSame('aquasec/trivy', $matches['depName'] ?? null);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/D', $matches['currentValue'] ?? '');
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/D', $matches['currentDigest'] ?? '');
        $this->assertSame($pin, $matches[0]);
    }

    public function test_cla_workflow_uses_trusted_base_checker_with_opener_scoped_allowlist(): void
    {
        $workflow = $this->projectFile('.github/workflows/cla.yml');
        $checker = $this->projectFile('scripts/cla/check.mjs');

        $this->assertStringContainsString("run-name: 'CLA PR #", $workflow);
        $this->assertStringContainsString('ref: ${{ github.sha }}', $workflow);
        $this->assertStringContainsString('persist-credentials: false', $workflow);
        $this->assertStringContainsString('run: node scripts/cla/check.mjs', $workflow);
        $this->assertSame(1, preg_match('/const ALLOWLIST = new Map\(\[(.*?)\]\);/s', $checker, $matches));
        $this->assertSame(4, preg_match_all("/\['[^']+', [0-9]+\]/", $matches[1] ?? '', $entries));
        $this->assertSame([
            "['gadsotek', 14184492]",
            "['dependabot[bot]', 49699333]",
            "['dependabot-preview[bot]', 27856297]",
            "['renovate[bot]', 29139614]",
        ], $entries[0]);
        $this->assertStringContainsString('id === trustedOpener.id && openerAllowlisted', $checker);
    }

    private function projectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
