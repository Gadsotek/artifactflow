<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class HostCookieConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, string|false, string}>
     */
    public static function sessionCookieNames(): iterable
    {
        yield 'secure default' => [true, false, '__Host-artifactflow_session'];
        yield 'secure custom name' => [true, 'team_session', '__Host-team_session'];
        yield 'already prefixed' => [true, '__Host-team_session', '__Host-team_session'];
        yield 'local HTTP default' => [false, false, 'artifactflow_session'];
        yield 'local HTTP custom name' => [false, 'team_session', 'team_session'];
    }

    #[DataProvider('sessionCookieNames')]
    public function test_session_cookie_configuration_uses_host_prefixes_for_secure_sessions(
        bool $secure,
        string|false $configuredName,
        string $expectedName,
    ): void {
        // Read only the committed config in a fresh process; never load a local .env.
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            new Illuminate\Foundation\Application(getcwd());
            $config = require 'config/session.php';
            echo $config['cookie'];
            PHP], base_path(), [
            'APP_NAME' => 'ArtifactFlow',
            'SESSION_COOKIE' => $configuredName,
            'SESSION_SECURE_COOKIE' => $secure ? 'true' : 'false',
        ]);
        $process->mustRun();

        $this->assertSame($expectedName, $process->getOutput());
    }
}
