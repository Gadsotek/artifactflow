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

    public function test_nested_browsing_contexts_are_neutralized_at_every_static_nesting_depth(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><body>'
            . '<iframe id="outer" srcdoc="&lt;iframe id=&quot;middle&quot; '
            . 'srcdoc=&quot;&amp;lt;iframe id=&amp;quot;inner&amp;quot;&amp;gt;&amp;lt;/iframe&amp;gt;&quot;&gt;'
            . '&lt;/iframe&gt;">fallback content</iframe>'
            . '<frame id="legacy" src="data:text/html,legacy">'
            . '<fencedframe id="fenced"></fencedframe>'
            . '<portal id="portal" src="data:text/html,portal"></portal>'
            . '<p id="safe-content">Safe artifact content</p>'
            . '</body></html>',
        );

        $this->assertStringNotContainsString('<iframe id="outer"', strtolower($hardened));
        $this->assertStringNotContainsString('<frame id="legacy"', strtolower($hardened));
        $this->assertStringNotContainsString('<fencedframe id="fenced"', strtolower($hardened));
        $this->assertStringNotContainsString('<portal id="portal"', strtolower($hardened));
        $this->assertStringContainsString('fallback content', $hardened);
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_nested_context_neutralization_preserves_script_text_textarea_text_and_safe_bytes(): void
    {
        $script = 'if(i<frame.count&&j<portal.count){window.result="<iframe data-literal>";}';
        $textarea = '<iframe src="/textarea-literal.html">textarea bytes</iframe>';
        $safeTemplateAndStrayClosing = '<template id="safe-template"><p>kept</p></template></iframe>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><body><p>before</p>'
            . '<script>' . $script . '</script>'
            . '<textarea>' . $textarea . '</textarea>'
            . $safeTemplateAndStrayClosing
            . '<iframe src="/real-frame.html"><script>window.fallbackRan=true</script></iframe>'
            . '<p>after</p></body></html>',
        );

        $this->assertStringContainsString('<script>' . $script . '</script>', $hardened);
        $this->assertStringContainsString('<textarea>' . $textarea . '</textarea>', $hardened);
        $this->assertStringContainsString($safeTemplateAndStrayClosing, $hardened);
        $this->assertStringContainsString('<p>before</p>', $hardened);
        $this->assertStringContainsString('<p>after</p>', $hardened);
        $this->assertStringNotContainsString('<iframe src="/real-frame.html">', $hardened);
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context src="/real-frame.html">',
            $hardened,
        );
        $this->assertStringContainsString('window.fallbackRan=true', $hardened);
    }
}
