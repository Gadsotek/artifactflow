<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every config scripts/connect-mcp.sh writes launches the mcp-remote bridge
 * through `npx`, so a machine without Node.js would receive configs whose
 * connection can never start. The script must refuse up front with install
 * guidance, before any prompt, token use, or config write, unless the caller
 * explicitly opts into config-only provisioning with MCP_SKIP_NODE_CHECK=1.
 *
 * PATH is restricted to a symlink sandbox of the tools the script needs, so
 * npx presence and absence are both deterministic regardless of whether the
 * machine running the suite has Node.js installed.
 */
final class ConnectMcpNodeRuntimeGuardTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            $this->removeTree($path);
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    public function test_missing_npx_is_refused_with_install_guidance_before_any_config_is_written(): void
    {
        [$process, $home, $codexHome] = $this->runConnect(withNpx: false);

        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('npx', $process->getErrorOutput());
        $this->assertStringContainsString('https://nodejs.org', $process->getErrorOutput());
        $this->assertStringContainsString('MCP_SKIP_NODE_CHECK=1', $process->getErrorOutput());
        $this->assertStringNotContainsString('Done. MCP server', $process->getOutput());
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_missing_npx_can_be_skipped_for_config_only_provisioning(): void
    {
        [$process, $home, $codexHome] = $this->runConnect(withNpx: false, skipNodeCheck: true);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('npx', $process->getErrorOutput());
        $this->assertStringContainsString('Done. MCP server', $process->getOutput());
        $this->assertNotSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_present_npx_passes_the_check_without_node_guidance(): void
    {
        [$process, $home, $codexHome] = $this->runConnect(withNpx: true);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringNotContainsString('nodejs.org', $process->getErrorOutput());
        $this->assertStringContainsString('Done. MCP server', $process->getOutput());
        $this->assertNotSame([], $this->configFilesUnder($home, $codexHome));
    }

    /**
     * @return array{Process, string, string}
     */
    private function runConnect(bool $withNpx, bool $skipNodeCheck = false): array
    {
        $home = $this->makeTempDirectory('af_connect_mcp_home_');
        $codexHome = $home . '/.codex';
        $toolPath = $this->makeToolPath($withNpx);

        $environment = [
            'MCP_URL' => 'https://artifactflow.example',
            'MCP_TOKEN' => 'af_mcp_test_token_value',
            'MCP_TARGETS' => 'all',
            'HOME' => $home,
            'CODEX_HOME' => $codexHome,
            'PATH' => $toolPath,
        ];

        if ($skipNodeCheck) {
            $environment['MCP_SKIP_NODE_CHECK'] = '1';
        }

        $process = new Process(
            [$toolPath . '/bash', base_path('scripts/connect-mcp.sh')],
            base_path(),
            $environment,
            null,
            30,
        );
        $process->run();

        return [$process, $home, $codexHome];
    }

    /**
     * Build a PATH directory holding only the standard tools the script uses,
     * optionally with a stub `npx`. curl and stty stay out on purpose: both are
     * optional to the script and curl would otherwise attempt a network call.
     */
    private function makeToolPath(bool $withNpx): string
    {
        $directory = $this->makeTempDirectory('af_connect_mcp_tools_');

        $tools = [
            'bash', 'awk', 'sed', 'grep', 'tr', 'cut', 'head', 'tail', 'uname',
            'basename', 'dirname', 'mktemp', 'cp', 'chmod', 'date', 'rm', 'mkdir', 'cat',
        ];

        foreach ($tools as $tool) {
            $resolve = new Process(['sh', '-c', 'command -v ' . $tool]);
            $resolve->run();
            $binary = trim($resolve->getOutput());

            if ($binary === '' || !is_file($binary)) {
                $this->fail(sprintf('Tool [%s] required for the PATH sandbox was not found.', $tool));
            }

            symlink($binary, $directory . '/' . $tool);
        }

        if ($withNpx) {
            file_put_contents($directory . '/npx', "#!/bin/sh\nexit 0\n");
            chmod($directory . '/npx', 0700);
        }

        return $directory;
    }

    /**
     * @return list<string>
     */
    private function configFilesUnder(string $home, string $codexHome): array
    {
        $candidates = [
            $home . '/Library/Application Support/Claude/claude_desktop_config.json',
            $home . '/.config/Claude/claude_desktop_config.json',
            $home . '/.claude.json',
            $codexHome . '/config.toml',
        ];

        return array_values(array_filter($candidates, static fn (string $path): bool => is_file($path)));
    }

    private function makeTempDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->tempPaths[] = $directory;

        return $directory;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }

            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
