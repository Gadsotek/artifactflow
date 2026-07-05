<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Infrastructure\Security\SecretStrength;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EnsureReverbKeysScriptTest extends TestCase
{
    private string $envPath = '';

    protected function tearDown(): void
    {
        if ($this->envPath !== '' && is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function test_it_generates_strong_reverb_keys_and_enables_broadcasting(): void
    {
        $this->writeEnv(
            "APP_ENV=local\n"
            . "BROADCAST_CONNECTION=null\n"
            . "REVERB_APP_ID=\n"
            . "REVERB_APP_KEY=\n"
            . "REVERB_APP_SECRET=\n"
            . "VITE_REVERB_APP_KEY=\n",
        );

        $result = $this->runScript();

        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertStringContainsString('Generated Reverb realtime keys', $result->getOutput());

        $env = $this->readEnv();
        $this->assertNotSame('', $this->value($env, 'REVERB_APP_ID'));
        $appKey = $this->value($env, 'REVERB_APP_KEY');
        $this->assertNotSame('', $appKey);
        $this->assertTrue(SecretStrength::isStrong($this->value($env, 'REVERB_APP_SECRET')));
        $this->assertSame($appKey, $this->value($env, 'VITE_REVERB_APP_KEY'));
        $this->assertSame('reverb', $this->value($env, 'BROADCAST_CONNECTION'));
    }

    public function test_it_is_idempotent_and_preserves_existing_values(): void
    {
        $existingSecret = str_repeat('a', 48);
        $this->writeEnv(
            "BROADCAST_CONNECTION=reverb\n"
            . "REVERB_APP_ID=existing-id\n"
            . "REVERB_APP_KEY=existing-key\n"
            . "REVERB_APP_SECRET={$existingSecret}\n"
            . "VITE_REVERB_APP_KEY=existing-key\n",
        );

        $result = $this->runScript();

        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertStringContainsString('already configured', $result->getOutput());

        $env = $this->readEnv();
        $this->assertSame('existing-id', $this->value($env, 'REVERB_APP_ID'));
        $this->assertSame('existing-key', $this->value($env, 'REVERB_APP_KEY'));
        $this->assertSame($existingSecret, $this->value($env, 'REVERB_APP_SECRET'));
        $this->assertSame('reverb', $this->value($env, 'BROADCAST_CONNECTION'));
    }

    private function writeEnv(string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'af_reverb_env_');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->envPath = $path;
    }

    private function readEnv(): string
    {
        $contents = file_get_contents($this->envPath);
        $this->assertIsString($contents);

        return $contents;
    }

    private function runScript(): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('scripts/ensure-reverb-keys.php'), $this->envPath],
            base_path(),
        );
        $process->run();

        return $process;
    }

    private function value(string $env, string $key): string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }
}
