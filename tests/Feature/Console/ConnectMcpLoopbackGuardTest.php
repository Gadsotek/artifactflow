<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/connect-mcp.sh refuses to send the bearer MCP token over plaintext
 * http:// to anything but a loopback host. The authority it inspects must be the
 * same one a URL client (curl / mcp-remote) resolves -- otherwise a userinfo
 * form such as `http://localhost:80@remote.example` reads as loopback to a naive
 * shell glob while the client sends the token in cleartext to remote.example.
 * These regressions pin the guard against that divergence.
 */
final class ConnectMcpLoopbackGuardTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempHomes = [];

    protected function tearDown(): void
    {
        foreach ($this->tempHomes as $home) {
            $this->removeTree($home);
        }

        $this->tempHomes = [];

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function userinfoLoopbackBypassProvider(): iterable
    {
        // Each authority a naive `localhost:*`/`127.0.0.1:*` glob would accept, yet a
        // URL client resolves the real host to the right of the userinfo `@`.
        yield 'port then userinfo host' => ['http://localhost:80@remote.example'];
        yield 'bare userinfo host' => ['http://localhost@remote.example'];
        yield 'ipv4 loopback userinfo host' => ['http://127.0.0.1@evil.example'];
        yield 'ipv4 loopback with creds' => ['http://127.0.0.1:80@evil.example'];
        yield 'ipv6 loopback userinfo host' => ['http://[::1]@evil.example'];
        yield 'backslash host confusion' => ['http://localhost:18080\\@evil.example'];
    }

    #[DataProvider('userinfoLoopbackBypassProvider')]
    public function test_plaintext_userinfo_targeting_a_non_loopback_host_is_refused(string $url): void
    {
        [$process, $home, $codexHome] = $this->runConnect($url);

        $this->assertNotSame(
            0,
            $process->getExitCode(),
            sprintf('Expected [%s] to be refused, got:%s%s', $url, PHP_EOL, $process->getOutput()),
        );
        $this->assertStringContainsString('plaintext HTTP', $process->getErrorOutput());
        // The guard must fire before the token is ever read or a config written, so
        // no client config file may exist on disk for a refused URL.
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_plaintext_to_a_plain_non_loopback_host_is_still_refused(): void
    {
        [$process] = $this->runConnect('http://remote.example');

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('plaintext HTTP', $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackFormProvider(): iterable
    {
        yield 'localhost with port' => ['http://localhost:18080'];
        yield 'localhost without port' => ['http://localhost'];
        yield 'ipv4 loopback with port' => ['http://127.0.0.1:18080'];
        yield 'ipv6 loopback with port' => ['http://[::1]:18080'];
        yield 'ipv6 loopback without port' => ['http://[::1]'];
    }

    #[DataProvider('loopbackFormProvider')]
    public function test_genuine_loopback_plaintext_targets_pass_the_guard(string $url): void
    {
        [$process] = $this->runConnect($url);

        // A real loopback target must clear the plaintext guard and finish writing the
        // client configs. (The best-effort token curl to a dead port is non-fatal.)
        $this->assertSame(
            0,
            $process->getExitCode(),
            sprintf('Expected [%s] to be accepted, stderr:%s%s', $url, PHP_EOL, $process->getErrorOutput()),
        );
        $this->assertStringNotContainsString('plaintext HTTP', $process->getErrorOutput());
        $this->assertStringContainsString('Done. MCP server', $process->getOutput());
    }

    public function test_existing_unmanaged_codex_server_is_replaced_without_duplicating_the_table(): void
    {
        $existingConfig = <<<'TOML'
model = "gpt-5"

[mcp_servers.artifactflow]
command = "old-bridge"
args = ["http://localhost:9999/mcp"]

[mcp_servers.other]
command = "other-server"
TOML;

        [$process, , $codexHome] = $this->runConnect('http://localhost:18080', $existingConfig);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $config = file_get_contents($codexHome . '/config.toml');

        $this->assertIsString($config);
        $this->assertSame(1, substr_count($config, '[mcp_servers.artifactflow]'));
        $this->assertStringNotContainsString('old-bridge', $config);
        $this->assertStringNotContainsString('localhost:9999', $config);
        $this->assertStringContainsString('model = "gpt-5"', $config);
        $this->assertStringContainsString('[mcp_servers.other]', $config);
        $this->assertStringContainsString('command = "other-server"', $config);
        $this->assertStringContainsString('http://localhost:18080/mcp', $config);
    }

    public function test_existing_duplicate_claude_servers_are_collapsed_to_one_entry(): void
    {
        $existingConfig = <<<'JSON'
{
  "theme": "dark",
  "mcpServers": {
    "artifactflow": {"command": "old-bridge-one"},
    "other": {"command": "other-server"},
    "artifactflow": {"command": "old-bridge-two"}
  }
}
JSON;

        [$process, $home] = $this->runConnect(
            'http://localhost:18080',
            existingClaudeConfig: $existingConfig,
        );

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $config = file_get_contents($home . '/.config/Claude/claude_desktop_config.json');

        $this->assertIsString($config);
        $this->assertSame(1, substr_count($config, '"artifactflow"'));
        $this->assertStringNotContainsString('old-bridge-one', $config);
        $this->assertStringNotContainsString('old-bridge-two', $config);
        $this->assertStringContainsString('"theme": "dark"', $config);
        $this->assertStringContainsString('"other": {"command": "other-server"}', $config);
        $this->assertStringContainsString('http://localhost:18080/mcp', $config);
    }

    /**
     * @return array{Process, string, string}
     */
    private function runConnect(
        string $url,
        ?string $existingCodexConfig = null,
        ?string $existingClaudeConfig = null,
    ): array {
        $home = $this->makeTempHome();
        $codexHome = $home . '/.codex';

        if ($existingCodexConfig !== null) {
            mkdir($codexHome, 0700, true);
            file_put_contents($codexHome . '/config.toml', $existingCodexConfig);
        }

        if ($existingClaudeConfig !== null) {
            $claudeConfigDirectory = $home . '/.config/Claude';
            mkdir($claudeConfigDirectory, 0700, true);
            file_put_contents($claudeConfigDirectory . '/claude_desktop_config.json', $existingClaudeConfig);
        }

        $process = new Process(
            ['bash', base_path('scripts/connect-mcp.sh')],
            base_path(),
            [
                'MCP_URL' => $url,
                'MCP_TOKEN' => 'af_mcp_test_token_value',
                'HOME' => $home,
                'CODEX_HOME' => $codexHome,
            ],
            null,
            30,
        );
        $process->run();

        return [$process, $home, $codexHome];
    }

    /**
     * @return list<string>
     */
    private function configFilesUnder(string $home, string $codexHome): array
    {
        $candidates = [
            $home . '/Library/Application Support/Claude/claude_desktop_config.json',
            $home . '/.config/Claude/claude_desktop_config.json',
            $codexHome . '/config.toml',
        ];

        return array_values(array_filter($candidates, static fn (string $path): bool => is_file($path)));
    }

    private function makeTempHome(): string
    {
        $home = sys_get_temp_dir() . '/af_connect_mcp_' . bin2hex(random_bytes(8));
        mkdir($home, 0700, true);
        $this->tempHomes[] = $home;

        return $home;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
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
