<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\ArtifactPreviewDocumentGuard;
use Tests\TestCase;

final class ArtifactPreviewDocumentGuardTest extends TestCase
{
    public function test_served_guard_body_is_the_single_shared_source_of_truth(): void
    {
        $canonical = file_get_contents(resource_path('js/artifact-preview-guard.js'));
        $this->assertIsString($canonical);
        $this->assertNotSame('', trim($canonical));

        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden('<!doctype html><html><head></head><body></body></html>');

        // The served guard wraps the exact canonical body the draft preview also imports.
        $this->assertStringContainsString('<script data-artifactflow-preview-guard>', $hardened);
        $this->assertStringContainsString($canonical, $hardened);
        $this->assertStringContainsString("defineValue(window, 'RTCPeerConnection', blockedConstructor)", $hardened);
        $this->assertStringContainsString("defineValue(window, 'WebTransport', blockedConstructor)", $hardened);
        // Programmatic self-navigation is neutralized where the browser allows it.
        $this->assertStringContainsString("defineValue(window.location, 'assign', noop)", $hardened);
        $this->assertStringContainsString("defineValue(window.location, 'replace', noop)", $hardened);
    }

    public function test_guard_is_injected_after_the_doctype_and_before_artifact_markup(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head></head><body><script>window.artifactRan = true;</script></body></html>',
        );

        $guardPosition = strpos($hardened, 'data-artifactflow-preview-guard');
        $artifactPosition = strpos($hardened, 'window.artifactRan');

        $this->assertIsInt($guardPosition);
        $this->assertIsInt($artifactPosition);
        $this->assertLessThan($artifactPosition, $guardPosition);
        $this->assertStringStartsWith('<!doctype html>', $hardened);
    }

    public function test_saved_preview_guard_enables_parent_mediated_url_refresh_without_enabling_it_for_drafts(): void
    {
        $guard = app(ArtifactPreviewDocumentGuard::class);
        $saved = $guard->harden('<!doctype html><p>Saved</p>', recoveryEnabled: true);
        $draft = $guard->harden('<!doctype html><p>Draft</p>');

        $this->assertStringContainsString(
            '<script data-artifactflow-preview-guard data-artifactflow-preview-recovery>',
            $saved,
        );
        $this->assertStringContainsString(
            "window.addEventListener('load', reportPreviewReady, { capture: true, once: true })",
            $saved,
        );
        $this->assertStringContainsString('<script data-artifactflow-preview-guard>', $draft);
        $this->assertStringNotContainsString(
            '<script data-artifactflow-preview-guard data-artifactflow-preview-recovery>',
            $draft,
        );
    }

    public function test_meta_refresh_tags_are_stripped(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head><meta http-equiv="refresh" content="0;url=https://evil.example.test"></head><body></body></html>',
        );

        $this->assertStringNotContainsString('http-equiv="refresh"', $hardened);
        $this->assertStringNotContainsString('evil.example.test', $hardened);
    }

    public function test_resource_hint_links_are_stripped_but_benign_links_are_kept(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head>'
            . '<link rel="dns-prefetch" href="//dns.evil.example.test">'
            . '<link rel="preconnect" href="https://connect.evil.example.test">'
            . '<link rel="prefetch" href="https://prefetch.evil.example.test">'
            . '<link rel="prerender" href="https://prerender.evil.example.test">'
            . '<link rel="PRECONNECT DNS-PREFETCH" href="https://mixed.evil.example.test">'
            . '<link rel="stylesheet" href="/local.css">'
            . '</head><body></body></html>',
        );

        $this->assertStringNotContainsString('dns.evil.example.test', $hardened);
        $this->assertStringNotContainsString('connect.evil.example.test', $hardened);
        $this->assertStringNotContainsString('prefetch.evil.example.test', $hardened);
        $this->assertStringNotContainsString('prerender.evil.example.test', $hardened);
        $this->assertStringNotContainsString('mixed.evil.example.test', $hardened);
        $this->assertStringContainsString('rel="stylesheet"', $hardened);
    }
}
