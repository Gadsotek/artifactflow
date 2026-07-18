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

    public function test_incorrectly_closed_html_comment_cannot_hide_a_nested_browsing_context(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><!-- --!>'
            . '<iframe id="comment-end-bang-breakout" srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
            . '<p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringContainsString('<!-- --!>', $hardened);
        $this->assertStringNotContainsString('<iframe id="comment-end-bang-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="comment-end-bang-breakout"',
            $hardened,
        );
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_abruptly_closed_empty_html_comments_cannot_hide_nested_browsing_contexts(): void
    {
        foreach (['<!-->', '<!--->'] as $comment) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $comment
                . '<iframe id="abrupt-comment-breakout" srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                . '<p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringContainsString($comment, $hardened);
            $this->assertStringNotContainsString('<iframe id="abrupt-comment-breakout"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="abrupt-comment-breakout"',
                $hardened,
            );
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_declaration_parser_differentials_cannot_hide_nested_browsing_contexts(): void
    {
        $declarationPrefixes = [
            'bogus-comment-breakout' => '<!x=">',
            'processing-instruction-breakout' => '<?xml x=">',
            'abrupt-doctype-breakout' => '<!DOCTYPE html PUBLIC ">',
            'html-cdata-breakout' => '<![CDATA[">',
        ];

        foreach ($declarationPrefixes as $iframeId => $declarationPrefix) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $declarationPrefix
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;">">'
                . '</iframe><p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringContainsString($declarationPrefix, $hardened);
            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_iframe_raw_text_cannot_close_its_neutralizing_template_wrapper(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><iframe></template>'
            . '<iframe id="breakout" srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
            . '</iframe><p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringNotContainsString(
            '<template data-artifactflow-blocked-browsing-context></template><iframe id="breakout"',
            $hardened,
        );
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context>&lt;/template>'
            . '&lt;iframe id="breakout" '
            . 'srcdoc="&amp;lt;script&amp;gt;new RTCPeerConnection()&amp;lt;/script&amp;gt;">'
            . '</template>',
            $hardened,
        );
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_unterminated_iframe_raw_text_cannot_close_its_neutralizing_template_wrapper(): void
    {
        // Same </template> breakout, but with NO closing </iframe>: the interior
        // runs to end-of-input and is emitted by the raw-text early-return path,
        // which must escape it exactly like the terminated path.
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><iframe></template>'
            . '<iframe id="breakout" srcdoc="&lt;script&gt;window.escaped=1&lt;/script&gt;">',
        );

        $this->assertStringNotContainsString('<iframe id="breakout"', strtolower($hardened));
        $this->assertStringNotContainsString(
            '<template data-artifactflow-blocked-browsing-context></template><iframe',
            $hardened,
        );
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context>&lt;/template>'
            . '&lt;iframe id="breakout" '
            . 'srcdoc="&amp;lt;script&amp;gt;window.escaped=1&amp;lt;/script&amp;gt;">',
            $hardened,
        );
    }

    public function test_nested_context_inside_svg_title_foreign_content_is_neutralized(): void
    {
        // SVG <title> is an HTML integration point: the browser parses its content
        // as HTML, so a nested <iframe srcdoc> there becomes a live browsing context
        // even though HTML <title> is RCDATA. The guard must not treat title as raw
        // text inside SVG/MathML, or the iframe slips through unneutralized.
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><title>'
            . '<iframe id="svg-title-breakout" srcdoc="&lt;script&gt;window.top.ran=1&lt;/script&gt;"></iframe>'
            . '</title></svg><p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringNotContainsString('<iframe id="svg-title-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<svg><title><template data-artifactflow-blocked-browsing-context id="svg-title-breakout"',
            $hardened,
        );
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_raw_text_title_outside_foreign_content_is_still_preserved_verbatim(): void
    {
        // Regression guard for the fix above: a plain HTML <title> is RCDATA, so its
        // literal "<iframe>" text is not markup and must stay byte-exact (not be
        // rewritten to a template) -- the foreign-content rule must only apply inside
        // <svg>/<math>.
        $title = '<title>Docs: the &lt;iframe&gt; element and <iframe> literal</title>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head>' . $title . '</head><body></body></html>',
        );

        $this->assertStringContainsString($title, $hardened);
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
