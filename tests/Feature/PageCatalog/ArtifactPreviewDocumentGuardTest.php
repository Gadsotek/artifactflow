<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\ArtifactPreviewDocumentGuard;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class ArtifactPreviewDocumentGuardTest extends TestCase
{
    public function test_missing_guard_source_fails_closed(): void
    {
        $application = app();
        $originalBasePath = $application->basePath();
        $guardBody = new ReflectionProperty(ArtifactPreviewDocumentGuard::class, 'guardBody');

        try {
            $application->setBasePath(
                sys_get_temp_dir() . '/artifactflow-missing-guard-' . bin2hex(random_bytes(8)),
            );
            $guardBody->setValue(null, null);

            $this->expectException(RuntimeException::class);
            app(ArtifactPreviewDocumentGuard::class)->harden('<!doctype html><p>inert</p>');
        } finally {
            $application->setBasePath($originalBasePath);
            $guardBody->setValue(null, null);
        }
    }

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
        $this->assertStringContainsString("normalizedCommand === 'inserthtml'", $hardened);
        $this->assertStringContainsString("defineValue(Document.prototype, 'execCommand'", $hardened);
        $this->assertStringContainsString("defineValue(Document, 'parseHTMLUnsafe', noop)", $hardened);
        $this->assertStringNotContainsString("hardenMarkupMethod(Document, 'parseHTMLUnsafe')", $hardened);
        $this->assertStringContainsString("defineValue(Element.prototype, 'setHTMLUnsafe', noop)", $hardened);
        $this->assertStringContainsString("defineValue(ShadowRoot.prototype, 'setHTMLUnsafe', noop)", $hardened);
        $this->assertStringNotContainsString("hardenMarkupMethod(Element.prototype, 'setHTMLUnsafe')", $hardened);
        $this->assertStringNotContainsString("hardenMarkupMethod(ShadowRoot.prototype, 'setHTMLUnsafe')", $hardened);
        $this->assertStringContainsString("hardenMarkupMethod(Document, 'parseHTML')", $hardened);
        $this->assertStringContainsString("hardenMarkupMethod(Element.prototype, 'setHTML')", $hardened);
        $this->assertStringContainsString("hardenMarkupMethod(ShadowRoot.prototype, 'setHTML')", $hardened);
        // These legacy-unforgeable properties cannot be patched. The guard
        // must not claim that a silent assignment fallback neutralized them.
        $this->assertStringNotContainsString("defineValue(window, 'top', blockedWindowProxy)", $hardened);
        $this->assertStringNotContainsString("defineValue(window.location, 'assign', noop)", $hardened);
        $this->assertStringNotContainsString("defineValue(window.location, 'replace', noop)", $hardened);
    }

    public function test_security_documentation_preserves_the_self_navigation_network_residual(): void
    {
        $canonical = file_get_contents(resource_path('js/artifact-preview-guard.js'));
        $threatModel = file_get_contents(base_path('THREAT-MODEL.md'));
        $operations = file_get_contents(base_path('docs/OPERATIONS.md'));

        $this->assertIsString($canonical);
        $this->assertIsString($threatModel);
        $this->assertIsString($operations);
        $this->assertStringNotContainsString('cannot reach app storage or the network', $canonical);
        $this->assertStringNotContainsString("can't reach an exfil endpoint", $threatModel);
        $this->assertStringContainsString('self-navigation can still send artifact-controlled or user-entered data', $threatModel);
        $this->assertStringContainsString('redact `/join/*`', $operations);
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
            "window.addEventListener('message', respondToPreviewReadyRequest, true)",
            $saved,
        );
        $this->assertStringContainsString("const previewReadyRequest = 'artifactflow:preview-ready-request'", $saved);
        $this->assertStringContainsString('<script data-artifactflow-preview-guard>', $draft);
        $this->assertStringNotContainsString(
            '<script data-artifactflow-preview-guard data-artifactflow-preview-recovery>',
            $draft,
        );
    }

    public function test_meta_refresh_tags_are_stripped(): void
    {
        $values = ['refresh', '&#114;efresh', '&#114efresh', '&#000114efresh'];

        foreach ($values as $index => $value) {
            $host = "refresh-{$index}.evil.example.test";
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html><html><head><meta http-equiv="' . $value
                . '" content="0;url=https://' . $host . '"></head><body></body></html>',
            );

            $this->assertStringNotContainsString($host, $hardened);
        }
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
            . '<link rel="&#112reconnect" href="https://numeric-decimal.evil.example.test">'
            . '<link rel="&#x70refetch" href="https://numeric-hex.evil.example.test">'
            . '<link rel="stylesheet" href="/local.css">'
            . '</head><body></body></html>',
        );

        $this->assertStringNotContainsString('dns.evil.example.test', $hardened);
        $this->assertStringNotContainsString('connect.evil.example.test', $hardened);
        $this->assertStringNotContainsString('prefetch.evil.example.test', $hardened);
        $this->assertStringNotContainsString('prerender.evil.example.test', $hardened);
        $this->assertStringNotContainsString('mixed.evil.example.test', $hardened);
        $this->assertStringNotContainsString('numeric-decimal.evil.example.test', $hardened);
        $this->assertStringNotContainsString('numeric-hex.evil.example.test', $hardened);
        $this->assertStringContainsString('rel="stylesheet"', $hardened);
    }

    public function test_first_duplicate_refresh_and_resource_hint_attributes_control_normalization(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head>'
            . '<meta http-equiv="x-safe" http-equiv="refresh" content="0;url=https://safe-meta.example.test">'
            . '<link rel="stylesheet" rel="preconnect" href="https://safe-link.example.test/style.css">'
            . '</head></html>',
        );

        $this->assertStringContainsString('safe-meta.example.test', $hardened);
        $this->assertStringContainsString('safe-link.example.test', $hardened);
    }

    public function test_numeric_attribute_references_follow_the_html_replacement_table_and_invalid_rules(): void
    {
        $references = [
            '&#128;',
            '&#130;',
            '&#131;',
            '&#132;',
            '&#133;',
            '&#134;',
            '&#135;',
            '&#136;',
            '&#137;',
            '&#x8a;',
            '&#x8B;',
            '&#140;',
            '&#142;',
            '&#145;',
            '&#146;',
            '&#147;',
            '&#148;',
            '&#149;',
            '&#150;',
            '&#151;',
            '&#152;',
            '&#153;',
            '&#x9a;',
            '&#x9B;',
            '&#156;',
            '&#158;',
            '&#159;',
            '&#0;',
            '&#55296;',
            '&#999999999999999999999999999999;',
        ];
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><html><head><link rel="'
            . implode(' ', $references)
            . '" href="https://safe.example.test/style.css"></head></html>',
        );

        $this->assertStringContainsString('safe.example.test/style.css', $hardened);
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

    public function test_nested_and_malformed_comment_states_resume_scanning_at_the_browser_boundary(): void
    {
        $comments = [
            '<!---->',
            '<!---x-->',
            '<!--a-x-->',
            '<!--a<x-->',
            '<!--a<<x-->',
            '<!--a<!x-->',
            '<!--a<!-x-->',
            '<!--a<!-- nested -->',
            '<!--a--!-x-->',
            '<!--a--!x-->',
        ];

        foreach ($comments as $index => $comment) {
            $iframeId = 'comment-state-' . $index;
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $comment . '<iframe id="' . $iframeId . '"></iframe>',
            );

            $this->assertStringContainsString($comment, $hardened);
            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
        }
    }

    public function test_unterminated_comment_declaration_tag_and_script_syntax_remains_inert(): void
    {
        $guard = app(ArtifactPreviewDocumentGuard::class);
        $inputs = [
            '<!doctype html><!--unterminated',
            '<!doctype html><!unterminated',
            '<!doctype html>< invalid-tag',
            '<!doctype html><div data-value="unterminated',
            '<!doctype html><script><!--unterminated',
        ];

        foreach ($inputs as $input) {
            $hardened = $guard->harden($input);

            $this->assertStringContainsString(substr($input, 15), $hardened);
            $this->assertStringNotContainsString('<template data-artifactflow-blocked-browsing-context', $hardened);
        }
    }

    public function test_invalid_raw_text_close_candidate_does_not_hide_the_real_close(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><style>literal</stylex></style><iframe id="after-real-style-close"></iframe>',
        );

        $this->assertStringContainsString('<style>literal</stylex></style>', $hardened);
        $this->assertStringNotContainsString('<iframe id="after-real-style-close"', strtolower($hardened));
    }

    public function test_plaintext_keeps_the_remaining_document_inert(): void
    {
        $literal = '<iframe id="plaintext-literal"></iframe>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><plaintext>' . $literal,
        );

        $this->assertStringContainsString('<plaintext>' . $literal, $hardened);
        $this->assertStringNotContainsString('<template data-artifactflow-blocked-browsing-context', $hardened);
    }

    public function test_unterminated_noscript_is_scanned_for_the_scripting_disabled_parse(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><noscript><iframe id="unterminated-noscript-breakout"></iframe>',
        );

        $this->assertStringNotContainsString('<iframe id="unterminated-noscript-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="unterminated-noscript-breakout"',
            $hardened,
        );
    }

    public function test_attribute_name_parse_errors_do_not_hide_a_following_context(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><div first second="value">'
            . '<iframe id="after-space-separated-attributes"></iframe></div>',
        );

        $this->assertStringNotContainsString('<iframe id="after-space-separated-attributes"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="after-space-separated-attributes"',
            $hardened,
        );
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

    public function test_quotes_inside_malformed_attribute_names_cannot_hide_nested_browsing_contexts(): void
    {
        foreach (["'", '"'] as $quote) {
            foreach (['' => 'quote', '=' => 'leading-equals-quote'] as $prefix => $case) {
                $iframeId = 'attribute-name-' . $case . '-breakout';
                $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                    '<!doctype html><div ' . $prefix . $quote . '>'
                    . '<iframe id="' . $iframeId . '" '
                    . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                    . $quote . '></div><p id="safe-content">Safe artifact content</p>',
                );

                $this->assertStringNotContainsString(
                    '<iframe id="' . $iframeId . '"',
                    strtolower($hardened),
                );
                $this->assertStringContainsString(
                    '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                    $hardened,
                );
                $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
            }
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

    public function test_foreign_content_text_elements_cannot_hide_nested_browsing_contexts(): void
    {
        $cases = [
            'svg-style-div' => ['<svg><style><div>', '</div></style></svg>'],
            'svg-style-br' => ['<svg><style><br>', '</style></svg>'],
            'svg-style-p' => ['<svg><style><p>', '</p></style></svg>'],
            'svg-style-unterminated' => ['<svg><style><div>', ''],
            'math-style-div' => ['<math><style><div>', '</div></style></math>'],
            'math-style-br' => ['<math><style><br>', '</style></math>'],
            'math-style-p' => ['<math><style><p>', '</p></style></math>'],
            'math-style-unterminated' => ['<math><style><div>', ''],
            'math-script-div' => ['<math><script><div>', '</div></script></math>'],
            'math-script-br' => ['<math><script><br>', '</script></math>'],
            'math-script-p' => ['<math><script><p>', '</p></script></math>'],
            'math-script-unterminated' => ['<math><script><div>', ''],
        ];

        foreach ($cases as $iframeId => [$prefix, $suffix]) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                . $suffix
                . '<p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_browser_parser_state_differentials_cannot_hide_nested_browsing_contexts(): void
    {
        $cases = [
            'svg-plaintext-breakout' => ['<svg><plaintext><p>', ''],
            'math-plaintext-breakout' => ['<math><plaintext><p>', ''],
            'svg-script-breakout' => ['<svg><script><p>', '</script>'],
            'html-noscript-style-breakout' => ['<noscript><style></noscript><p>', '</style>'],
        ];

        foreach ($cases as $iframeId => [$prefix, $suffix]) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                . $suffix
                . '<p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_script_double_escaped_state_cannot_hide_nested_browsing_contexts(): void
    {
        $carriers = ['title', 'textarea', 'style', 'xmp', 'noembed', 'noframes'];
        $doubleEscapedPrefixes = [
            'once' => '<!--<script>',
            'twice' => '<!--<script><script>',
        ];

        foreach ($doubleEscapedPrefixes as $depth => $doubleEscapedPrefix) {
            foreach ($carriers as $carrier) {
                $iframeId = sprintf('script-double-escaped-%s-%s-breakout', $depth, $carrier);
                $script = '<script>' . $doubleEscapedPrefix . '</script><' . $carrier . '></script>';
                $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                    '<!doctype html>' . $script
                    . '<iframe id="' . $iframeId . '" '
                    . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                    . '<p id="safe-content">Safe artifact content</p>',
                );

                $this->assertStringContainsString($script, $hardened);
                $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
                $this->assertStringContainsString(
                    '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                    $hardened,
                );
                $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
            }
        }
    }

    public function test_script_escaped_state_closes_normally_and_preserves_following_rcdata_bytes(): void
    {
        $inertTitle = '<title></script><iframe id="escaped-state-control"></iframe>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><script><!--escaped</script>' . $inertTitle,
        );

        $this->assertStringContainsString(
            '<script><!--escaped</script>' . $inertTitle,
            $hardened,
        );
        $this->assertStringNotContainsString(
            '<template data-artifactflow-blocked-browsing-context id="escaped-state-control"',
            $hardened,
        );
    }

    public function test_script_comment_end_restores_data_state_before_the_closing_tag(): void
    {
        $prefixes = [
            'escaped' => '<!--escaped--><script>',
            'double-escaped' => '<!--<script>--><script>',
        ];

        foreach ($prefixes as $state => $prefix) {
            $iframeId = 'script-comment-end-' . $state . '-breakout';
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html><script>' . $prefix . '</script>'
                . '<iframe id="' . $iframeId . '" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
        }
    }

    public function test_overlapping_script_comment_end_restores_data_state_before_the_closing_tag(): void
    {
        foreach (['<!-->', '<!--->'] as $escapedPrefix) {
            $iframeId = $escapedPrefix === '<!-->'
                ? 'script-comment-overlap-empty-breakout'
                : 'script-comment-overlap-dash-breakout';
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html><script>' . $escapedPrefix . '<script></script>'
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe></script>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
        }
    }

    public function test_plaintext_is_not_treated_as_terminal_when_the_tree_builder_ignores_it(): void
    {
        $cases = [
            'frameset' => '<frameset><plaintext><frame id="frameset-plaintext-breakout" src="/nested">',
            'select' => '<select><plaintext></select>'
                . '<iframe id="select-plaintext-breakout" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>',
            'self-closing-frameset' => '<frameset/><plaintext>'
                . '<frame id="self-closing-frameset-plaintext-breakout" src="/nested">',
            'self-closing-select' => '<select/><plaintext></select>'
                . '<iframe id="self-closing-select-plaintext-breakout" '
                . 'srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>',
        ];

        foreach ($cases as $context => $markup) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $markup . '<p id="safe-' . $context . '">Safe</p>',
            );

            $this->assertStringNotContainsString('<frame id="frameset-plaintext-breakout"', strtolower($hardened));
            $this->assertStringNotContainsString('<iframe id="select-plaintext-breakout"', strtolower($hardened));
            $this->assertStringNotContainsString(
                '<frame id="self-closing-frameset-plaintext-breakout"',
                strtolower($hardened),
            );
            $this->assertStringNotContainsString(
                '<iframe id="self-closing-select-plaintext-breakout"',
                strtolower($hardened),
            );
            $this->assertStringContainsString('data-artifactflow-blocked-browsing-context', $hardened);
        }
    }

    public function test_raw_text_is_not_entered_when_the_tree_builder_ignores_the_start_tag(): void
    {
        $cases = [
            'select-style-breakout' => '<select><style></select>',
            'frameset-style-breakout' => '<frameset><style></frameset>',
        ];

        foreach ($cases as $iframeId => $prefix) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
        }
    }

    public function test_ignored_frameset_inside_select_does_not_enable_noframes_raw_text(): void
    {
        $iframeId = 'select-ignored-frameset-noframes-breakout';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><select><option><frameset>--><noframes></select>'
            . '<iframe data-decoy=">" id="' . $iframeId . '"></iframe>',
        );

        $this->assertStringNotContainsString('<iframe data-decoy=">" id="' . $iframeId . '"', $hardened);
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context data-decoy=">" id="'
            . $iframeId . '"',
            $hardened,
        );
    }

    public function test_select_noframes_engine_differential_cannot_hide_a_later_context(): void
    {
        $iframeId = 'select-noframes-engine-breakout';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><table><select></style><noframes><script><svg>'
            . '<noframes><xmp></noframes><iframe id="' . $iframeId . '"></iframe>',
        );

        $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
            $hardened,
        );
    }

    public function test_recognized_raw_text_cannot_desynchronize_insertion_mode_state(): void
    {
        $cases = [
            'frameset-noframes-plaintext-breakout' => [
                '<frameset><noframes></frameset></noframes><plaintext>',
                'frame',
            ],
            'select-script-plaintext-breakout' => [
                '<select><script>/*</select>*/</script><plaintext></select>',
                'iframe',
            ],
        ];

        foreach ($cases as $contextId => [$prefix, $tagName]) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix
                . '<' . $tagName . ' id="' . $contextId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;">'
                . ($tagName === 'iframe' ? '</iframe>' : ''),
            );

            $this->assertStringNotContainsString(
                '<' . $tagName . ' id="' . $contextId . '"',
                strtolower($hardened),
            );
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $contextId . '"',
                $hardened,
            );
        }
    }

    public function test_noscript_is_hardened_for_both_scripting_parser_modes(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><noscript>'
            . '<iframe id="scripting-disabled-breakout" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>'
            . '</noscript><p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringNotContainsString('<iframe id="scripting-disabled-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<noscript><template data-artifactflow-blocked-browsing-context '
            . 'id="scripting-disabled-breakout"',
            $hardened,
        );
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_foreign_cdata_is_hardened_for_cdata_and_bogus_comment_engines(): void
    {
        $integrationPoints = [
            'svg-foreign-object' => ['<svg><foreignObject>', '</foreignObject></svg>'],
            'svg-desc' => ['<svg><desc>', '</desc></svg>'],
            'svg-title' => ['<svg><title>', '</title></svg>'],
            'math-annotation-html' => [
                '<math><annotation-xml encoding="text/html">',
                '</annotation-xml></math>',
            ],
            'math-mtext' => ['<math><mtext>', '</mtext></math>'],
        ];

        foreach ($integrationPoints as $context => [$openingMarkup, $closingMarkup]) {
            foreach (['style', 'title', 'textarea'] as $carrier) {
                $iframeId = sprintf('foreign-cdata-%s-%s-breakout', $context, $carrier);
                $cdata = '<![CDATA[x><' . $carrier . '>]]>';
                $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                    '<!doctype html>' . $openingMarkup . $cdata . $closingMarkup
                    . '<iframe id="' . $iframeId . '" '
                    . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>',
                );

                $this->assertStringContainsString($cdata, $hardened);
                $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
                $this->assertStringContainsString(
                    '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                    $hardened,
                );
            }
        }

        $inlineIframeId = 'foreign-cdata-bogus-comment-inline';
        $inlineIframe = '<iframe id="' . $inlineIframeId . '" '
            . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><foreignObject><![CDATA[x>'
            . $inlineIframe
            . ']]></foreignObject></svg>',
        );

        $this->assertStringNotContainsString('<iframe id="' . $inlineIframeId . '"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="' . $inlineIframeId . '"',
            $hardened,
        );
        $this->assertStringContainsString(']]></foreignObject></svg>', $hardened);
    }

    public function test_cdata_outside_an_html_integration_point_is_preserved_verbatim(): void
    {
        $cdata = '<![CDATA[x><iframe id="foreign-cdata-literal"></iframe>]]>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><g>' . $cdata . '</g></svg>',
        );

        $this->assertStringContainsString($cdata, $hardened);
    }

    public function test_frameset_alternate_does_not_corrupt_inert_foreign_cdata(): void
    {
        $liveFrameId = 'frameset-alternate-live-frame';
        $cdata = '<![CDATA[x><iframe id="frameset-alternate-cdata-literal"></iframe>]]>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><div>body</div>'
            . '<frameset><noframes></frameset></noframes><plaintext>'
            . '<frame id="' . $liveFrameId . '" src="about:blank">'
            . '<svg><g>' . $cdata . '</g></svg>',
        );

        $this->assertStringNotContainsString('<frame id="' . $liveFrameId . '"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="' . $liveFrameId . '"',
            $hardened,
        );
        $this->assertStringContainsString($cdata, $hardened);
    }

    public function test_frameset_cdata_alternate_respects_noframes_raw_text(): void
    {
        $rawTextFrameId = 'frameset-cdata-noframes-literal';
        $liveFrameId = 'frameset-cdata-live-frame';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><frameset><svg><![CDATA[x>'
            . '<noframes><frame id="' . $rawTextFrameId . '"></noframes>'
            . '<frame id="' . $liveFrameId . '">]]></svg>',
        );

        $this->assertStringContainsString('<frame id="' . $rawTextFrameId . '">', $hardened);
        $this->assertStringNotContainsString('<frame id="' . $liveFrameId . '">', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="' . $liveFrameId . '">',
            $hardened,
        );

        $unterminatedRawTextFrameId = 'frameset-cdata-unterminated-noframes-literal';
        $unterminated = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><frameset><svg><![CDATA[x><noframes>'
            . '<frame id="' . $unterminatedRawTextFrameId . '">]]>',
        );

        $this->assertStringContainsString('<frame id="' . $unterminatedRawTextFrameId . '">', $unterminated);
    }

    public function test_frameset_cdata_alternate_preserves_non_tag_syntax_while_scanning_frames(): void
    {
        $liveFrameId = 'frameset-cdata-after-non-tag-syntax';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><frameset><svg><![CDATA[x>'
            . '<!--preserved-comment--><!preserved-declaration>< '
            . '<frame id="' . $liveFrameId . '">]]>',
        );

        $this->assertStringContainsString('<!--preserved-comment-->', $hardened);
        $this->assertStringContainsString('<!preserved-declaration>', $hardened);
        $this->assertStringContainsString('< ', $hardened);
        $this->assertStringNotContainsString('<frame id="' . $liveFrameId . '">', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="' . $liveFrameId . '">',
            $hardened,
        );
    }

    public function test_frameset_cdata_alternate_preserves_unterminated_and_delimiter_free_sections(): void
    {
        $guard = app(ArtifactPreviewDocumentGuard::class);
        $sections = [
            '<![CDATA[x]]>',
            '<![CDATA[x><!--unterminated',
            '<![CDATA[x><!unterminated',
            '<![CDATA[x><div>',
        ];

        foreach ($sections as $section) {
            $hardened = $guard->harden('<!doctype html><frameset><svg>' . $section);

            $this->assertStringContainsString($section, $hardened);
        }
    }

    public function test_foreign_br_and_p_end_tags_break_out_before_following_cdata(): void
    {
        $prefixes = [
            'svg-p' => '<svg></p>',
            'svg-br' => '<svg></br>',
            'math-p' => '<math></p>',
            'nested-svg-p' => '<svg><g></p>',
        ];

        foreach ($prefixes as $case => $prefix) {
            $iframeId = 'foreign-end-' . $case . '-breakout';
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix . '<![CDATA[x>'
                . '<iframe id="' . $iframeId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>',
            );

            $this->assertStringNotContainsString('<iframe id="' . $iframeId . '"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                $hardened,
            );
        }
    }

    public function test_ignored_foreign_start_tags_do_not_change_cdata_handling(): void
    {
        $cases = [
            'frameset-svg' => [
                '<frameset><svg><![CDATA[x>',
                'frame',
            ],
            'select-svg' => [
                '<select><svg></select><![CDATA[x>',
                'iframe',
            ],
            'select-foreign-end' => [
                '<svg><foreignObject><select></foreignObject></select><![CDATA[x>',
                'iframe',
            ],
        ];

        foreach ($cases as $case => [$prefix, $tagName]) {
            $contextId = 'ignored-foreign-' . $case . '-breakout';
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $prefix
                . '<' . $tagName . ' id="' . $contextId . '" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;">'
                . ($tagName === 'iframe' ? '</iframe>' : '')
                . ']]>',
            );

            $this->assertStringNotContainsString(
                '<' . $tagName . ' id="' . $contextId . '"',
                strtolower($hardened),
            );
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $contextId . '"',
                $hardened,
            );
        }
    }

    public function test_html_integration_point_raw_text_cannot_desynchronize_foreign_content_state(): void
    {
        $style = '</svg><script>';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><foreignObject><style>' . $style . '</style>'
            . '<div><template shadowrootmode="closed">'
            . '<iframe id="integration-point-breakout" '
            . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
            . '</template></div></foreignObject></svg>',
        );

        $this->assertStringContainsString('<style>' . $style . '</style>', $hardened);
        $this->assertStringNotContainsString(' shadowrootmode=', strtolower($hardened));
        $this->assertStringNotContainsString('<iframe id="integration-point-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="integration-point-breakout"',
            $hardened,
        );
    }

    public function test_html_integration_points_preserve_legitimate_raw_text_bytes(): void
    {
        $script = 'window.example = "<iframe data-literal>";';
        $cases = [
            '<svg><foreignObject><script>' . $script . '</script></foreignObject></svg>',
            '<math><mtext><script>' . $script . '</script></mtext></math>',
            '<math><annotation-xml encoding="text/html"><script>'
                . $script . '</script></annotation-xml></math>',
            '<math><annotation-xml encoding="text/html"><svg><title><script>'
                . $script . '</script></title></svg></annotation-xml></math>',
        ];

        foreach ($cases as $markup) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $markup . '<p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringContainsString('<script>' . $script . '</script>', $hardened);
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_annotation_xml_dispatches_svg_without_an_encoding_attribute(): void
    {
        $integrationCandidates = ['mi', 'mn', 'mo', 'ms', 'mtext'];
        $rawTextCandidates = [
            'style',
            'script',
            'textarea',
            'title',
            'xmp',
            'noembed',
            'noframes',
            'plaintext',
        ];

        foreach ($integrationCandidates as $integrationCandidate) {
            foreach ($rawTextCandidates as $rawTextCandidate) {
                $iframeId = sprintf(
                    'annotation-svg-%s-%s-breakout',
                    $integrationCandidate,
                    $rawTextCandidate,
                );
                $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                    '<!doctype html><math><annotation-xml><svg>'
                    . '<' . $integrationCandidate . '><' . $rawTextCandidate . '><b>'
                    . '<iframe id="' . $iframeId . '" '
                    . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                    . '</b></' . $rawTextCandidate . '></' . $integrationCandidate . '>'
                    . '</svg></annotation-xml></math>',
                );

                $this->assertStringNotContainsString(
                    '<iframe id="' . $iframeId . '"',
                    strtolower($hardened),
                );
                $this->assertStringContainsString(
                    '<template data-artifactflow-blocked-browsing-context id="' . $iframeId . '"',
                    $hardened,
                );
            }
        }
    }

    public function test_duplicate_annotation_encoding_cannot_fake_an_html_integration_point(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><math><annotation-xml '
            . 'encoding="application/xml" encoding="text/html"><script><p>'
            . '<iframe id="duplicate-encoding-breakout" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>'
            . '</p></script></annotation-xml></math>',
        );

        $this->assertStringNotContainsString('<iframe id="duplicate-encoding-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="duplicate-encoding-breakout"',
            $hardened,
        );
    }

    public function test_foreign_content_breakout_tokens_restore_html_raw_text_rules(): void
    {
        $script = 'window.example = "<iframe data-literal>";';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><g><p><script>' . $script . '</script>'
            . '<iframe id="real-breakout" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>',
        );

        $this->assertStringContainsString('<script>' . $script . '</script>', $hardened);
        $this->assertStringNotContainsString('<iframe id="real-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="real-breakout"',
            $hardened,
        );
    }

    public function test_conditional_font_foreign_content_breakout_restores_html_raw_text_rules(): void
    {
        $script = 'window.example = "<iframe data-literal>";';
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><g><font color="red"><script>' . $script . '</script>'
            . '<iframe id="conditional-font-breakout" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>',
        );

        $this->assertStringContainsString('<script>' . $script . '</script>', $hardened);
        $this->assertStringNotContainsString('<iframe id="conditional-font-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="conditional-font-breakout"',
            $hardened,
        );
    }

    public function test_unmatched_foreign_end_tags_are_processed_in_bounded_time(): void
    {
        $html = '<!doctype html>'
            . str_repeat('<svg>', 12_000)
            . str_repeat('</math>', 12_000)
            . '<iframe id="bounded-work" srcdoc="&lt;p&gt;nested&lt;/p&gt;"></iframe>';
        $startedAt = hrtime(true);

        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden($html);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertLessThan(2.0, $elapsedSeconds);
        $this->assertStringNotContainsString('<iframe id="bounded-work"', strtolower($hardened));
    }

    public function test_static_declarative_shadow_roots_are_neutralized_before_browser_parsing(): void
    {
        foreach (['open', 'closed'] as $mode) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html><div><template shadowrootmode="' . $mode . '">'
                . '<iframe id="' . $mode . '-shadow-frame" '
                . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
                . '</template></div><p id="safe-content">Safe artifact content</p>',
            );

            $this->assertStringNotContainsString(' shadowrootmode=', strtolower($hardened));
            $this->assertStringContainsString('data-artifactflow-blocked-shadow-root="' . $mode . '"', $hardened);
            $this->assertStringNotContainsString('<iframe id="' . $mode . '-shadow-frame"', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context id="' . $mode . '-shadow-frame"',
                $hardened,
            );
            $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
        }
    }

    public function test_synthesized_templates_cannot_retain_declarative_shadow_root_attributes(): void
    {
        $cases = [
            'portal' => ['open', '<span>Portal fallback</span>', '</portal>'],
            'fencedframe' => ['closed', '<span>Fenced frame fallback</span>', '</fencedframe>'],
            'iframe' => ['open', '<span>Iframe fallback</span>', '</iframe>'],
            'frame' => ['closed', '', ''],
        ];

        foreach ($cases as $tagName => [$mode, $contents, $closingTag]) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html><' . $tagName . ' SHADOWROOTMODE="' . $mode . '">'
                . $contents
                . $closingTag,
            );

            $this->assertStringNotContainsString(' shadowrootmode=', strtolower($hardened));
            $this->assertStringContainsString(
                '<template data-artifactflow-blocked-browsing-context '
                . 'data-artifactflow-blocked-shadow-root="' . $mode . '"',
                $hardened,
            );
        }
    }

    public function test_shadow_root_attribute_neutralization_respects_html_attribute_boundaries(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><template data-note=" shadowrootmode=&quot;closed&quot;" '
            . 'SHADOWROOTMODE=open shadowrootmode="closed"><p>Template content</p></template>',
        );

        $this->assertStringContainsString(
            'data-note=" shadowrootmode=&quot;closed&quot;"',
            $hardened,
        );
        $this->assertStringNotContainsString(' SHADOWROOTMODE=', $hardened);
        $this->assertStringNotContainsString(' shadowrootmode="closed"', $hardened);
        $this->assertSame(2, substr_count($hardened, 'data-artifactflow-blocked-shadow-root='));
    }

    public function test_shadow_root_attribute_neutralization_tracks_malformed_html_attribute_states(): void
    {
        $plainTemplate = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><template><span>Plain template content</span></template>',
        );

        $this->assertStringContainsString('<template><span>Plain template content</span></template>', $plainTemplate);
        $this->assertStringNotContainsString('data-artifactflow-blocked-shadow-root', $plainTemplate);

        $openingTags = [
            '<template/shadowrootmode=open>',
            '<template shadowrootmode/open>',
            '<template shadowrootmode /x>',
            '<template shadowrootmode = open>',
            "<template shadowrootmode = 'open'>",
            '<template shadowrootmode=>',
            "<template shadowrootmode='open'/>",
            '<template data-note="value"shadowrootmode=open>',
            '<template shadowrootmode   >',
        ];

        foreach ($openingTags as $openingTag) {
            $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
                '<!doctype html>' . $openingTag . '<span>Template content</span></template>',
            );

            $this->assertStringContainsString('data-artifactflow-blocked-shadow-root', $hardened);
            $this->assertDoesNotMatchRegularExpression(
                '/<template[^>]*(?:\s|\/)shadowrootmode(?:\s|=|\/|>)/i',
                $hardened,
            );
        }
    }

    public function test_svg_script_contents_are_scanned_as_foreign_markup(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg><script><p>'
            . '<iframe id="svg-script-context" '
            . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
            . '</script></svg>',
        );

        $this->assertStringNotContainsString('<iframe id="svg-script-context"', strtolower($hardened));
        $this->assertStringContainsString(
            '<template data-artifactflow-blocked-browsing-context id="svg-script-context"',
            $hardened,
        );
    }

    public function test_slash_in_unquoted_svg_attribute_value_does_not_fake_a_self_closing_tag(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg data-value=1/><title>'
            . '<iframe id="unquoted-slash-breakout" '
            . 'srcdoc="&lt;script&gt;window.top.ran=1&lt;/script&gt;"></iframe>'
            . '</title></svg><p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringNotContainsString('<iframe id="unquoted-slash-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<svg data-value=1/><title><template data-artifactflow-blocked-browsing-context '
            . 'id="unquoted-slash-breakout"',
            $hardened,
        );
        $this->assertStringContainsString('<p id="safe-content">Safe artifact content</p>', $hardened);
    }

    public function test_unmatched_foreign_end_tag_cannot_hide_nested_context_inside_svg_title(): void
    {
        $hardened = app(ArtifactPreviewDocumentGuard::class)->harden(
            '<!doctype html><svg></math><title>'
            . '<iframe id="unmatched-foreign-end-breakout" '
            . 'srcdoc="&lt;script&gt;new RTCPeerConnection()&lt;/script&gt;"></iframe>'
            . '</title></svg><p id="safe-content">Safe artifact content</p>',
        );

        $this->assertStringNotContainsString('<iframe id="unmatched-foreign-end-breakout"', strtolower($hardened));
        $this->assertStringContainsString(
            '<svg></math><title><template data-artifactflow-blocked-browsing-context '
            . 'id="unmatched-foreign-end-breakout"',
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
