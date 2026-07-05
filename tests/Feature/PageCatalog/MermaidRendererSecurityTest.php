<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\MarkdownPageRenderer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

/**
 * Fast drift guard for the Mermaid renderer's security configuration, plus a
 * behavioral check that the *server-side* decoration keeps a hostile diagram
 * source inert. The full client-render behavioral proof (strict mode, SVG
 * scrubbing) lives in tests/e2e/mermaid-security.spec.ts.
 */
final class MermaidRendererSecurityTest extends TestCase
{
    public function test_hostile_mermaid_source_is_decorated_inert_not_executable(): void
    {
        $renderer = app(MarkdownPageRenderer::class);

        // A mermaid "source" that tries to break out of the <details> wrapper and inject
        // a live <script>/<img onerror> the client renderer would otherwise trust.
        $payload = 'graph TD; A["</details><script>window.__pwned=1</script>'
            . '<img src=x onerror=window.__pwned=1>"] --> B';
        $html = $renderer->render("```mermaid\n{$payload}\n```");

        // The block is still decorated for the client-side renderer...
        $this->assertStringContainsString('data-mermaid-diagram', $html);
        $this->assertStringContainsString('artifactflow-mermaid-canvas', $html);

        // ...but the payload never becomes live markup. Parse the output and prove it
        // structurally: no <script>/<img> element exists; the source survives only as
        // inert attribute/text data for the client renderer to sanitize on its own.
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertSame(0, $document->getElementsByTagName('script')->length, 'Mermaid source must not spawn a live <script>.');
        $this->assertSame(0, $document->getElementsByTagName('img')->length, 'Mermaid source must not spawn a live <img> onerror handler.');

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//div[@data-mermaid-diagram]');
        $this->assertNotFalse($nodes);
        $wrapper = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $wrapper);
        // The raw source is preserved only as an inert data attribute, entity-escaped.
        $this->assertStringContainsString('window.__pwned=1', $wrapper->getAttribute('data-mermaid-source'));
    }

    public function test_mermaid_dependency_and_renderer_security_invariants_are_pinned(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertIsArray($package);
        $dependencies = $package['dependencies'] ?? null;
        $this->assertIsArray($dependencies);
        $this->assertSame('11.16.0', $dependencies['mermaid'] ?? null);

        $renderer = (string) file_get_contents(base_path('resources/js/mermaid-renderer.js'));

        $this->assertStringContainsString("securityLevel: 'strict'", $renderer);
        $this->assertStringContainsString('htmlLabels: false', $renderer);
        $this->assertStringContainsString("'script, foreignObject, iframe, object, embed, image'", $renderer);
        $this->assertStringContainsString("attributeName.startsWith('on')", $renderer);
        $this->assertStringContainsString("attributeName === 'href' || attributeName === 'xlink:href' || attributeName === 'src'", $renderer);
        $this->assertStringContainsString('canvas.innerHTML = safeSvg(svg)', $renderer);
    }
}
