<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every supported local client uses the same reviewed stdio-to-HTTP bridge so
 * older Codex releases remain compatible. The generated command must execute
 * the integrity-locked bridge directly through an absolute Node.js path; client
 * working-directory support and npx package resolution are not portable.
 */
final class ConnectMcpNodeRuntimeGuardTest extends TestCase
{
    private const string LOCK_SHA256 = 'b97684e30eabf216ede47d4cf20d4f2f75026277dc0bbf42e55808fc2262a768';

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

    public function test_codex_only_configuration_uses_the_integrity_locked_stdio_bridge(): void
    {
        [$process, $home, $codexHome, $toolPath] = $this->runConnect(
            targets: '3',
            withNpm: true,
        );

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $config = file_get_contents($codexHome . '/config.toml');
        $this->assertIsString($config);
        $bridgeEntrypoint = $home . '/.local/share/artifactflow/mcp-remote/0.2.1-'
            . self::LOCK_SHA256 . '/node_modules/mcp-remote/dist/proxy.js';

        $this->assertStringContainsString('command = "' . $toolPath . '/node"', $config);
        $this->assertStringContainsString('"' . $bridgeEntrypoint . '"', $config);
        $this->assertStringContainsString('"https://artifactflow.example/mcp"', $config);
        $this->assertStringContainsString('AUTH_HEADER = "Bearer af_mcp_test_token_value"', $config);
        $this->assertStringNotContainsString('npx', $config);
        $this->assertStringNotContainsString('http_headers', $config);
    }

    public function test_selected_bridge_requires_npm_before_any_config_is_written(): void
    {
        [$process, $home, $codexHome] = $this->runConnect(
            targets: '2',
            withNpm: false,
        );

        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('npm', $process->getErrorOutput());
        $this->assertStringNotContainsString('npx', $process->getErrorOutput());
        $this->assertStringNotContainsString('Done. MCP server', $process->getOutput());
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_claude_bridge_refuses_a_node_runtime_below_the_locked_engine_floor(): void
    {
        [$process, $home, $codexHome] = $this->runConnect(
            targets: '2',
            withNpm: true,
            nodeVersion: '20.18.0',
        );

        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('Node.js 20.18.1 or newer', $process->getErrorOutput());
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_claude_config_runs_the_integrity_locked_bridge_through_absolute_paths(): void
    {
        [$process, $home, , $toolPath] = $this->runConnect(
            targets: '2',
            withNpm: true,
        );

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $bridgeHome = $home . '/.local/share/artifactflow/mcp-remote/0.2.1-' . self::LOCK_SHA256;
        $config = json_decode((string) file_get_contents($home . '/.claude.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($config);
        $servers = $config['mcpServers'] ?? null;
        $this->assertIsArray($servers);
        $server = $servers['artifactflow'] ?? null;

        $this->assertIsArray($server);
        $this->assertSame($toolPath . '/node', $server['command'] ?? null);
        $this->assertArrayNotHasKey('cwd', $server);
        $arguments = $server['args'] ?? null;
        $this->assertIsArray($arguments);
        $this->assertSame($bridgeHome . '/node_modules/mcp-remote/dist/proxy.js', $arguments[0] ?? null);
        $this->assertSame('https://artifactflow.example/mcp', $arguments[1] ?? null);
        $this->assertNotContains('--offline', $arguments);
        $this->assertNotContains('--no-install', $arguments);
        $environment = $server['env'] ?? null;
        $this->assertIsArray($environment);
        $this->assertSame('Bearer af_mcp_test_token_value', $environment['AUTH_HEADER'] ?? null);
        $this->assertArrayNotHasKey('NPM_CONFIG_CACHE', $environment);
        $this->assertArrayNotHasKey('NPM_CONFIG_OFFLINE', $environment);

        $package = json_decode((string) file_get_contents($bridgeHome . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($package);
        $dependencies = $package['dependencies'] ?? null;
        $this->assertIsArray($dependencies);
        $lock = (string) file_get_contents($bridgeHome . '/package-lock.json');
        $this->assertSame('0.2.1', $dependencies['mcp-remote'] ?? null);
        $this->assertStringContainsString(
            'sha512-YgUAt8911M+kG7XGipuLeKHPKwX4rA8o2xybxF3RsLhdf+fvCueowR8CnznD/OqvYb00egQ90ymGQcqgi6DWwQ==',
            $lock,
        );

        $npmArguments = file_get_contents($home . '/npm-invocation.log');
        $this->assertIsString($npmArguments);
        $this->assertStringContainsString('ci', $npmArguments);
        $this->assertStringContainsString('--engine-strict', $npmArguments);
        $this->assertStringContainsString('--ignore-scripts', $npmArguments);
        $this->assertStringContainsString('--prefix ' . $bridgeHome, $npmArguments);
    }

    public function test_failed_locked_bridge_upgrade_preserves_the_last_known_good_install_and_config(): void
    {
        $home = $this->makeTempDirectory('af_connect_mcp_home_');
        $oldBridgeHome = $home . '/.local/share/artifactflow/mcp-remote/0.1.37-prior-lock';
        mkdir($oldBridgeHome, 0700, true);
        file_put_contents($oldBridgeHome . '/working-marker', "last-known-good\n");
        $existingConfig = sprintf(
            "{\n  \"mcpServers\": {\n    \"artifactflow\": {\"command\": \"npx\", \"cwd\": \"%s\"}\n  }\n}\n",
            $oldBridgeHome,
        );
        file_put_contents($home . '/.claude.json', $existingConfig);

        [$process] = $this->runConnect(
            targets: '2',
            withNpm: true,
            npmFails: true,
            homeOverride: $home,
        );

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertFileExists($oldBridgeHome . '/working-marker');
        $this->assertSame($existingConfig, file_get_contents($home . '/.claude.json'));
    }

    public function test_node_floor_comparison_is_derived_from_the_declared_semver(): void
    {
        $script = $this->modifiedConnector([
            'MCP_REMOTE_MIN_NODE_VERSION="20.18.1"' => 'MCP_REMOTE_MIN_NODE_VERSION="21.0.0"',
        ]);

        [$process, $home, $codexHome] = $this->runConnect(
            targets: '2',
            withNpm: true,
            nodeVersion: '20.99.99',
            scriptPath: $script,
            withLegacyNpx: true,
        );

        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('Node.js 21.0.0 or newer', $process->getErrorOutput());
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    public function test_install_validation_is_derived_from_the_declared_bridge_version(): void
    {
        $script = $this->modifiedConnector([
            'MCP_REMOTE_VERSION="0.2.1"' => 'MCP_REMOTE_VERSION="9.8.7"',
        ]);

        [$process, $home, $codexHome] = $this->runConnect(
            targets: '2',
            withNpm: true,
            scriptPath: $script,
            withLegacyNpx: true,
        );

        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('mcp-remote@9.8.7', $process->getErrorOutput());
        $this->assertSame([], $this->configFilesUnder($home, $codexHome));
    }

    /**
     * @return array{Process, string, string, string}
     */
    private function runConnect(
        string $targets,
        bool $withNpm,
        ?string $nodeVersion = '20.18.1',
        bool $npmFails = false,
        ?string $homeOverride = null,
        ?string $scriptPath = null,
        bool $withLegacyNpx = false,
    ): array {
        $home = $homeOverride ?? $this->makeTempDirectory('af_connect_mcp_home_');
        $codexHome = $home . '/.codex';
        $toolPath = $this->makeToolPath($withNpm, $nodeVersion, $withLegacyNpx);

        $environment = [
            'MCP_URL' => 'https://artifactflow.example',
            'MCP_TOKEN' => 'af_mcp_test_token_value',
            'MCP_TARGETS' => $targets,
            'HOME' => $home,
            'CODEX_HOME' => $codexHome,
            'PATH' => $toolPath,
            'FAKE_NPM_LOG' => $home . '/npm-invocation.log',
            'FAKE_NPM_FAIL' => $npmFails ? '1' : '0',
        ];

        $process = new Process(
            [$toolPath . '/bash', $scriptPath ?? base_path('scripts/connect-mcp.sh')],
            base_path(),
            $environment,
            null,
            30,
        );
        $process->run();

        return [$process, $home, $codexHome, $toolPath];
    }

    /**
     * Build a PATH directory holding only the standard tools the script uses.
     * curl and stty stay out on purpose: both are optional and curl would
     * otherwise attempt a network call.
     */
    private function makeToolPath(bool $withNpm, ?string $nodeVersion, bool $withLegacyNpx): string
    {
        $directory = $this->makeTempDirectory('af_connect_mcp_tools_');

        $tools = [
            'bash', 'awk', 'sed', 'grep', 'tr', 'cut', 'head', 'tail', 'uname',
            'basename', 'dirname', 'mktemp', 'cp', 'cmp', 'chmod', 'date', 'rm', 'mkdir', 'cat', 'env',
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

        if ($withLegacyNpx) {
            file_put_contents($directory . '/npx', "#!/bin/sh\nexit 0\n");
            chmod($directory . '/npx', 0700);
        }

        if ($nodeVersion !== null) {
            file_put_contents(
                $directory . '/node',
                sprintf(
                    "#!/bin/sh\nif [ \"\${1:-}\" = \"--version\" ]; then printf 'v%%s\\n' '%s'; else printf '%%s' '%s'; fi\n",
                    $nodeVersion,
                    self::LOCK_SHA256,
                ),
            );
            chmod($directory . '/node', 0700);
        }

        if ($withNpm) {
            $npm = <<<'SH'
#!/bin/sh
set -eu
[ -z "${MCP_TOKEN+x}" ] || exit 92
printf '%s\n' "$*" > "$FAKE_NPM_LOG"
[ "${FAKE_NPM_FAIL:-0}" = "0" ] || exit 86
prefix=""
previous=""
for argument in "$@"; do
    if [ "$previous" = "--prefix" ]; then
        prefix="$argument"
        break
    fi
    case "$argument" in --prefix=*) prefix="${argument#--prefix=}"; break ;; esac
    previous="$argument"
done
[ -n "$prefix" ] || exit 91
mkdir -p "$prefix/node_modules/.bin" "$prefix/node_modules/mcp-remote/dist"
printf '#!/bin/sh\nexit 0\n' > "$prefix/node_modules/.bin/mcp-remote"
chmod 700 "$prefix/node_modules/.bin/mcp-remote"
printf '{"name":"mcp-remote","version":"0.2.1"}\n' > "$prefix/node_modules/mcp-remote/package.json"
printf 'process.exit(0);\n' > "$prefix/node_modules/mcp-remote/dist/proxy.js"
SH;
            file_put_contents($directory . '/npm', $npm);
            chmod($directory . '/npm', 0700);
        }

        return $directory;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function modifiedConnector(array $replacements): string
    {
        $directory = $this->makeTempDirectory('af_connect_mcp_script_');
        $bridgeDirectory = $directory . '/mcp-remote-bridge';
        mkdir($bridgeDirectory, 0700, true);
        copy(base_path('scripts/mcp-remote-bridge/package.json'), $bridgeDirectory . '/package.json');
        copy(base_path('scripts/mcp-remote-bridge/package-lock.json'), $bridgeDirectory . '/package-lock.json');

        $source = file_get_contents(base_path('scripts/connect-mcp.sh'));
        $this->assertIsString($source);

        foreach ($replacements as $from => $to) {
            $replaced = str_replace($from, $to, $source, $count);
            $this->assertSame(1, $count, sprintf('Expected one connector declaration [%s].', $from));
            $source = $replaced;
        }

        $script = $directory . '/connect-mcp.sh';
        file_put_contents($script, $source);
        chmod($script, 0700);

        return $script;
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
