<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_artifacts_reports_a_clean_store(): void
    {
        Storage::fake('artifacts');
        $this->createStoredVersion('first artifact bytes');
        $this->createStoredVersion('second artifact bytes');
        $this->createStoredVersion('third artifact bytes');

        $exitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            'Artifact verification complete: checked=3, ok=3, missing_file=0, hash_mismatch=0.' . PHP_EOL,
            Artisan::output(),
        );
    }

    public function test_verify_artifacts_exits_non_zero_when_a_file_is_missing(): void
    {
        Storage::fake('artifacts');
        $this->createStoredVersion('present artifact bytes');
        PageVersion::factory()
            ->withContent('missing artifact bytes')
            ->create();

        $exitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame([
            'checked' => 2,
            'ok' => 1,
            'missing_file' => 1,
            'hash_mismatch' => 0,
        ], $this->decodeJsonOutput());
    }

    public function test_verify_artifacts_exits_non_zero_when_a_file_hash_mismatches(): void
    {
        Storage::fake('artifacts');
        $this->createStoredVersion('expected artifact bytes', 'tampered artifact bytes');

        $exitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame([
            'checked' => 1,
            'ok' => 0,
            'missing_file' => 0,
            'hash_mismatch' => 1,
        ], $this->decodeJsonOutput());
    }

    public function test_verify_artifacts_honors_sampling_and_all_mode(): void
    {
        Storage::fake('artifacts');

        for ($index = 0; $index < 5; $index++) {
            $this->createStoredVersion('artifact bytes ' . $index);
        }

        $sampleExitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--sample' => 2,
            '--json' => true,
        ]);

        $this->assertSame(0, $sampleExitCode);
        $this->assertSame([
            'checked' => 2,
            'ok' => 2,
            'missing_file' => 0,
            'hash_mismatch' => 0,
        ], $this->decodeJsonOutput());

        $allExitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $allExitCode);
        $this->assertSame([
            'checked' => 5,
            'ok' => 5,
            'missing_file' => 0,
            'hash_mismatch' => 0,
        ], $this->decodeJsonOutput());
    }

    public function test_verify_artifacts_output_never_discloses_secrets_or_artifact_bytes(): void
    {
        Storage::fake('artifacts');
        config([
            'app.key' => 'base64:do-not-print-app-key',
            'app.artifact_url_signing_key' => 'do-not-print-signing-key',
            'database.connections.pgsql.password' => 'do-not-print-db-password',
        ]);
        $this->createStoredVersion('<!doctype html><script>const privateNeedle = "never-print-me";</script>');

        $exitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('do-not-print-app-key', $output);
        $this->assertStringNotContainsString('do-not-print-signing-key', $output);
        $this->assertStringNotContainsString('do-not-print-db-password', $output);
        $this->assertStringNotContainsString('never-print-me', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }

    public function test_verify_artifacts_allows_an_empty_store(): void
    {
        Storage::fake('artifacts');

        $exitCode = Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'checked' => 0,
            'ok' => 0,
            'missing_file' => 0,
            'hash_mismatch' => 0,
        ], $this->decodeJsonOutput());
    }

    private function createStoredVersion(string $expectedContent, ?string $storedContent = null): PageVersion
    {
        $page = Page::factory()->create();
        $version = PageVersion::factory()
            ->forPage($page)
            ->withContent($expectedContent)
            ->create();

        $page->forceFill(['current_version_uid' => $version->uid])->save();
        Storage::disk('artifacts')->put($version->content_storage_path, $storedContent ?? $expectedContent);

        return $version;
    }

    /**
     * @return array{checked: int, ok: int, missing_file: int, hash_mismatch: int}
     */
    private function decodeJsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return [
            'checked' => $this->jsonInt($decoded, 'checked'),
            'ok' => $this->jsonInt($decoded, 'ok'),
            'missing_file' => $this->jsonInt($decoded, 'missing_file'),
            'hash_mismatch' => $this->jsonInt($decoded, 'hash_mismatch'),
        ];
    }

    /**
     * @param array<mixed> $decoded
     */
    private function jsonInt(array $decoded, string $key): int
    {
        $this->assertArrayHasKey($key, $decoded);
        $value = $decoded[$key];
        $this->assertIsInt($value);

        return $value;
    }
}
