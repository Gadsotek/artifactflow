<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Diagnostics\InstallationSecret;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class LocalSecretProvisioningScriptTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = storage_path('framework/testing/local-secrets-' . Str::random(12));
        file_put_contents(
            $this->envPath,
            "ARTIFACT_URL_SIGNING_KEY=\nIMAGE_PARSER_SHARED_SECRET=\nPDF_PROCESSOR_SHARED_SECRET=\n",
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function test_php_local_secret_setup_generates_distinct_idempotent_boundary_secrets(): void
    {
        $this->assertGeneratesDistinctIdempotentSecrets([
            PHP_BINARY,
            base_path('scripts/ensure-artifact-signing-key.php'),
        ]);
    }

    public function test_shell_fallback_generates_distinct_idempotent_boundary_secrets(): void
    {
        $this->assertGeneratesDistinctIdempotentSecrets([
            'sh',
            base_path('scripts/ensure-artifact-signing-key.sh'),
        ]);
    }

    /**
     * @param list<string> $command
     */
    private function assertGeneratesDistinctIdempotentSecrets(array $command): void
    {
        $first = Process::path(base_path())->run([...$command, $this->envPath]);

        $this->assertTrue($first->successful(), $first->errorOutput());
        $generated = $this->values();

        $this->assertFalse(InstallationSecret::isMissing($generated['ARTIFACT_URL_SIGNING_KEY']));
        $this->assertFalse(InstallationSecret::isMissing($generated['IMAGE_PARSER_SHARED_SECRET']));
        $this->assertFalse(InstallationSecret::isMissing($generated['PDF_PROCESSOR_SHARED_SECRET']));
        $this->assertNotSame(
            $generated['ARTIFACT_URL_SIGNING_KEY'],
            $generated['IMAGE_PARSER_SHARED_SECRET'],
        );
        $this->assertNotSame(
            $generated['ARTIFACT_URL_SIGNING_KEY'],
            $generated['PDF_PROCESSOR_SHARED_SECRET'],
        );
        $this->assertNotSame(
            $generated['IMAGE_PARSER_SHARED_SECRET'],
            $generated['PDF_PROCESSOR_SHARED_SECRET'],
        );

        $second = Process::path(base_path())->run([...$command, $this->envPath]);

        $this->assertTrue($second->successful(), $second->errorOutput());
        $this->assertSame($generated, $this->values());
    }

    /**
     * @return array{ARTIFACT_URL_SIGNING_KEY: string, IMAGE_PARSER_SHARED_SECRET: string, PDF_PROCESSOR_SHARED_SECRET: string}
     */
    private function values(): array
    {
        $contents = file_get_contents($this->envPath);
        $this->assertIsString($contents);

        $signingKey = $this->value($contents, 'ARTIFACT_URL_SIGNING_KEY');
        $parserSecret = $this->value($contents, 'IMAGE_PARSER_SHARED_SECRET');
        $pdfProcessorSecret = $this->value($contents, 'PDF_PROCESSOR_SHARED_SECRET');

        return [
            'ARTIFACT_URL_SIGNING_KEY' => $signingKey,
            'IMAGE_PARSER_SHARED_SECRET' => $parserSecret,
            'PDF_PROCESSOR_SHARED_SECRET' => $pdfProcessorSecret,
        ];
    }

    private function value(string $contents, string $key): string
    {
        $matched = preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $contents, $matches);

        if ($matched !== 1) {
            throw new RuntimeException(sprintf('Generated environment value [%s] was not found.', $key));
        }

        return $matches[1];
    }
}
