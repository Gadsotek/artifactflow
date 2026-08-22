<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Diagnostics\InstallationSecret;
use App\Infrastructure\Security\SecretStrength;
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

    public function test_php_local_secret_setup_repairs_weak_duplicate_and_reused_boundary_secrets(): void
    {
        $this->assertRepairsWeakDuplicateAndReusedBoundarySecrets([
            PHP_BINARY,
            base_path('scripts/ensure-artifact-signing-key.php'),
        ]);
    }

    public function test_shell_local_secret_setup_repairs_weak_duplicate_and_reused_boundary_secrets(): void
    {
        $this->assertRepairsWeakDuplicateAndReusedBoundarySecrets([
            'sh',
            base_path('scripts/ensure-artifact-signing-key.sh'),
        ]);
    }

    public function test_php_pdf_secret_setup_replaces_values_the_installer_rejects(): void
    {
        $this->assertPdfSecretSetupReplacesWeakValues([
            PHP_BINARY,
            base_path('scripts/ensure-pdf-processor-shared-secret.php'),
        ]);
    }

    public function test_shell_pdf_secret_setup_replaces_values_the_installer_rejects(): void
    {
        $this->assertPdfSecretSetupReplacesWeakValues([
            'sh',
            base_path('scripts/ensure-pdf-processor-shared-secret.sh'),
        ]);
    }

    public function test_php_pdf_secret_setup_preserves_strong_quoted_values_with_comments(): void
    {
        $this->assertPdfSecretSetupPreservesStrongQuotedValues([
            PHP_BINARY,
            base_path('scripts/ensure-pdf-processor-shared-secret.php'),
        ]);
    }

    public function test_shell_pdf_secret_setup_preserves_strong_quoted_values_with_comments(): void
    {
        $this->assertPdfSecretSetupPreservesStrongQuotedValues([
            'sh',
            base_path('scripts/ensure-pdf-processor-shared-secret.sh'),
        ]);
    }

    public function test_php_pdf_secret_setup_replaces_duplicate_assignments_with_a_weak_effective_value(): void
    {
        $this->assertPdfSecretSetupReplacesDuplicateAssignments([
            PHP_BINARY,
            base_path('scripts/ensure-pdf-processor-shared-secret.php'),
        ]);
    }

    public function test_shell_pdf_secret_setup_replaces_duplicate_assignments_with_a_weak_effective_value(): void
    {
        $this->assertPdfSecretSetupReplacesDuplicateAssignments([
            'sh',
            base_path('scripts/ensure-pdf-processor-shared-secret.sh'),
        ]);
    }

    public function test_shell_pdf_secret_setup_rejects_noncanonical_base64_when_only_openssl_can_decode(): void
    {
        $toolPath = storage_path('framework/testing/pdf-secret-tools-' . Str::random(12));
        mkdir($toolPath, 0700);
        file_put_contents($toolPath . '/base64', "#!/bin/sh\nexit 1\n");
        chmod($toolPath . '/base64', 0700);

        try {
            $canonical = base64_encode(str_repeat('x', 32));
            file_put_contents(
                $this->envPath,
                "PDF_PROCESSOR_SHARED_SECRET=base64:{$canonical}=\n",
            );

            $result = Process::env([
                'PATH' => $toolPath . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
            ])->path(base_path())->run([
                'sh',
                base_path('scripts/ensure-pdf-processor-shared-secret.sh'),
                $this->envPath,
            ]);

            $this->assertTrue($result->successful(), $result->errorOutput());
            $generated = $this->value(
                (string) file_get_contents($this->envPath),
                'PDF_PROCESSOR_SHARED_SECRET',
            );
            $this->assertNotSame('base64:' . $canonical . '=', $generated);
            $this->assertFalse(InstallationSecret::isMissing($generated));
        } finally {
            if (is_file($toolPath . '/base64')) {
                unlink($toolPath . '/base64');
            }

            if (is_dir($toolPath)) {
                rmdir($toolPath);
            }
        }
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
     * @param list<string> $command
     */
    private function assertRepairsWeakDuplicateAndReusedBoundarySecrets(array $command): void
    {
        $reused = 'base64:' . base64_encode(str_repeat('r', 32));
        file_put_contents(
            $this->envPath,
            "APP_KEY={$reused}\n"
            . 'ARTIFACT_URL_SIGNING_KEY=' . str_repeat('s', 32) . "\n"
            . "ARTIFACT_URL_SIGNING_KEY=artifact-preview-test-signing-key # published fixture wins\n"
            . "IMAGE_PARSER_SHARED_SECRET={$reused}\n"
            . "IMAGE_PARSER_SHARED_SECRET=short # weak effective value\n"
            . "PDF_PROCESSOR_SHARED_SECRET={$reused}\n",
        );

        $result = Process::path(base_path())->run([...$command, $this->envPath]);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $contents = (string) file_get_contents($this->envPath);
        $generated = $this->values();

        foreach ($generated as $key => $secret) {
            $this->assertTrue(SecretStrength::isProductionSafe($secret), $key);
            $this->assertNotSame(
                SecretStrength::normalized($reused),
                SecretStrength::normalized($secret),
                $key,
            );
        }

        $normalized = array_map(
            static fn (string $secret): ?string => SecretStrength::normalized($secret),
            array_values($generated),
        );
        $this->assertCount(3, array_unique($normalized));

        foreach (['ARTIFACT_URL_SIGNING_KEY', 'IMAGE_PARSER_SHARED_SECRET'] as $key) {
            preg_match_all('/^' . preg_quote($key, '/') . '=(.+)$/m', $contents, $matches);
            $this->assertCount(2, $matches[1], $key);
            $this->assertCount(1, array_unique($matches[1]), $key);
        }

        $rerun = Process::path(base_path())->run([...$command, $this->envPath]);

        $this->assertTrue($rerun->successful(), $rerun->errorOutput());
        $this->assertSame($contents, file_get_contents($this->envPath));
    }

    /**
     * @param list<string> $command
     */
    private function assertPdfSecretSetupReplacesWeakValues(array $command): void
    {
        foreach (
            [
                'quoted empty' => '""',
                'short' => 'too-short',
                'unquoted inline comment' => 'short # explanatory text long enough to exceed 32 bytes',
                'single-quoted inline comment' => "'short' # explanatory text long enough to exceed 32 bytes",
                'double-quoted inline comment' => '"short" # explanatory text long enough to exceed 32 bytes',
                'interpolated value' => '${PDF_PROCESSOR_SECRET_THAT_IS_NOT_DEFINED}',
                'invalid base64' => 'base64:not-valid-base64',
                'published local placeholder' => 'artifactflow-local-pdf-processor-secret-not-for-production',
                'encoded published local placeholder' => 'base64:YXJ0aWZhY3RmbG93LWxvY2FsLXBkZi1wcm9jZXNzb3Itc2VjcmV0LW5vdC1mb3ItcHJvZHVjdGlvbg==',
            ] as $label => $weakValue
        ) {
            file_put_contents($this->envPath, "PDF_PROCESSOR_SHARED_SECRET={$weakValue}\n");

            $result = Process::path(base_path())->run([...$command, $this->envPath]);

            $this->assertTrue($result->successful(), $label . ': ' . $result->errorOutput());
            $generated = $this->value(
                (string) file_get_contents($this->envPath),
                'PDF_PROCESSOR_SHARED_SECRET',
            );
            $this->assertFalse(InstallationSecret::isMissing($generated), $label);
            $this->assertNotSame($weakValue, $generated, $label);

            $rerun = Process::path(base_path())->run([...$command, $this->envPath]);

            $this->assertTrue($rerun->successful(), $label . ': ' . $rerun->errorOutput());
            $this->assertSame(
                $generated,
                $this->value((string) file_get_contents($this->envPath), 'PDF_PROCESSOR_SHARED_SECRET'),
                $label,
            );
        }
    }

    /**
     * @param list<string> $command
     */
    private function assertPdfSecretSetupPreservesStrongQuotedValues(array $command): void
    {
        foreach (
            [
                'single quoted' => "'" . str_repeat('s', 32) . "' # retained comment",
                'double quoted' => '"' . str_repeat('d', 32) . '" # retained comment',
            ] as $label => $configuredValue
        ) {
            $contents = "PDF_PROCESSOR_SHARED_SECRET={$configuredValue}\n";
            file_put_contents($this->envPath, $contents);

            $result = Process::path(base_path())->run([...$command, $this->envPath]);

            $this->assertTrue($result->successful(), $label . ': ' . $result->errorOutput());
            $this->assertSame($contents, file_get_contents($this->envPath), $label);
        }
    }

    /**
     * @param list<string> $command
     */
    private function assertPdfSecretSetupReplacesDuplicateAssignments(array $command): void
    {
        file_put_contents(
            $this->envPath,
            'PDF_PROCESSOR_SHARED_SECRET=' . str_repeat('s', 32) . "\n"
            . "PDF_PROCESSOR_SHARED_SECRET=short # effective value\n",
        );

        $result = Process::path(base_path())->run([...$command, $this->envPath]);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $contents = (string) file_get_contents($this->envPath);
        preg_match_all('/^PDF_PROCESSOR_SHARED_SECRET=(.+)$/m', $contents, $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertCount(1, array_unique($matches[1]));
        $this->assertFalse(InstallationSecret::isMissing($matches[1][0]));
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
