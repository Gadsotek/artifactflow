<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

final class ArtifactPreviewSigningKeyTest extends TestCase
{
    public function test_missing_artifact_signing_key_does_not_fall_back_to_application_key(): void
    {
        config([
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.artifact_url_signing_key' => '',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $page = new Page();
        $page->forceFill(['uid' => '01K00000000000000000000000']);
        $version = new PageVersion();
        $version->forceFill(['uid' => '01K00000000000000000000001']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Artifact preview signing key is not configured.');

        app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
    }

    public function test_weak_artifact_signing_key_is_rejected_when_issuing_urls(): void
    {
        config([
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.artifact_url_signing_key' => 'base64:' . base64_encode('too-short'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Artifact preview signing key must be a non-placeholder 32-byte secret.');

        app(ArtifactPreviewUrl::class)->temporaryUrl($this->page(), $this->version());
    }

    public function test_artifact_preview_url_ttl_is_capped_to_sixty_seconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00'));

        try {
            config([
                'app.artifact_url' => 'https://artifacts.example.test',
                'app.artifact_url_signing_key' => str_repeat('k', 32),
                'app.artifact_preview_url_ttl_seconds' => 3600,
            ]);

            $url = app(ArtifactPreviewUrl::class)->temporaryUrl($this->page(), $this->version());
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $expires = $query['expires'] ?? null;

            $this->assertIsString($expires);
            $this->assertSame((string) (Carbon::now()->getTimestamp() + 60), $expires);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_artifact_preview_signature_requires_canonical_expiration_bytes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00'));

        try {
            config([
                'app.artifact_url' => 'https://artifacts.example.test',
                'app.artifact_url_signing_key' => str_repeat('k', 32),
            ]);

            $page = $this->page();
            $version = $this->version();
            $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page, $version);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $expires = $query['expires'] ?? null;
            $signature = $query['signature'] ?? null;

            $this->assertIsString($expires);
            $this->assertIsString($signature);
            $this->assertTrue(app(ArtifactPreviewUrl::class)->hasValidSignature(
                $page,
                $version->uid,
                $expires,
                $signature,
            ));
            $this->assertFalse(app(ArtifactPreviewUrl::class)->hasValidSignature(
                $page,
                $version->uid,
                '0' . $expires,
                $signature,
            ));
            $this->assertFalse(app(ArtifactPreviewUrl::class)->hasValidSignature(
                $page,
                $version->uid,
                str_repeat('9', 13),
                $signature,
            ));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function page(): Page
    {
        $page = new Page();
        $page->forceFill(['uid' => '01K00000000000000000000000']);

        return $page;
    }

    private function version(): PageVersion
    {
        $version = new PageVersion();
        $version->forceFill(['uid' => '01K00000000000000000000001']);

        return $version;
    }
}
