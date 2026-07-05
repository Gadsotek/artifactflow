<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

final readonly class MarkdownPageRenderer
{
    public function __construct(
        private MarkdownWikiLinkResolver $wikiLinks,
    ) {
    }

    public function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'allow_unsafe_links' => false,
            'html_input' => 'strip',
        ]);

        return $this->decorateMermaidBlocks($html);
    }

    public function renderForPage(User $actor, Page $page, string $markdown): string
    {
        return $this->resolveWikiLinksForPage($actor, $page, $this->render($markdown));
    }

    public function resolveWikiLinksForPage(User $actor, Page $page, string $renderedHtml): string
    {
        return $this->wikiLinks->resolve(actor: $actor, sourcePage: $page, html: $renderedHtml);
    }

    private function decorateMermaidBlocks(string $html): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                sprintf(
                    // <meta charset> keeps libxml from decoding UTF-8 as ISO-8859-1 and
                    // double-encoding multibyte prose (café -> cafÃ©) on the rendered view.
                    '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="artifactflow-markdown-root">%s</div></body></html>',
                    $html,
                ),
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            $root = $document->getElementById('artifactflow-markdown-root');

            if (!$root instanceof DOMElement) {
                return $html;
            }

            $xpath = new DOMXPath($document);
            $this->removeUnsafeLinks($xpath);
            $this->openMarkdownLinksInNewTab($xpath);

            foreach ($xpath->query('//pre/code[contains(concat(" ", normalize-space(@class), " "), " language-mermaid ")]') ?: [] as $code) {
                if (!$code instanceof DOMElement || !$code->parentNode instanceof DOMElement) {
                    continue;
                }

                $pre = $code->parentNode;
                $wrapper = $document->createElement('div');
                $wrapper->setAttribute('class', 'artifactflow-mermaid');
                $wrapper->setAttribute('data-mermaid-diagram', '');
                $wrapper->setAttribute('data-mermaid-source', trim($code->textContent));

                $pre->parentNode?->replaceChild($wrapper, $pre);

                $canvas = $document->createElement('div');
                $canvas->setAttribute('class', 'artifactflow-mermaid-canvas');
                $canvas->setAttribute('data-mermaid-canvas', '');
                $canvas->setAttribute('role', 'img');
                $canvas->setAttribute('aria-label', 'Mermaid diagram');
                $wrapper->appendChild($canvas);

                $details = $document->createElement('details');
                $details->setAttribute('class', 'artifactflow-mermaid-source');
                $summary = $document->createElement('summary', 'Diagram source');
                $details->appendChild($summary);
                $pre->setAttribute('class', trim($pre->getAttribute('class') . ' artifactflow-mermaid-source-code'));
                $details->appendChild($pre);
                $wrapper->appendChild($details);
            }

            return $this->innerHtml($root);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function removeUnsafeLinks(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//a[@href]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            if ($this->isUnsafeLinkUri($element->getAttribute('href'))) {
                $element->removeAttribute('href');
            }
        }

        foreach ($xpath->query('//img[@src]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            if ($this->isUnsafeImageSource($element->getAttribute('src'))) {
                $element->removeAttribute('src');
            }
        }
    }

    private function openMarkdownLinksInNewTab(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//a[@href]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isUnsafeLinkUri(string $uri): bool
    {
        return $this->usesUnsafeScheme($uri, allowRasterDataImage: false);
    }

    private function isUnsafeImageSource(string $uri): bool
    {
        return $this->usesUnsafeScheme($uri, allowRasterDataImage: true);
    }

    private function usesUnsafeScheme(string $uri, bool $allowRasterDataImage): bool
    {
        $normalized = html_entity_decode($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decoded = rawurldecode($normalized);

            if ($decoded === $normalized) {
                break;
            }

            $normalized = $decoded;
        }

        $normalized = preg_replace('/^[\x00-\x20\x7F]+/u', '', $normalized);

        if (!is_string($normalized)) {
            return true;
        }

        $schemeCandidate = preg_replace('/[\x00-\x20\x7F]+/u', '', $normalized);

        if (!is_string($schemeCandidate)) {
            return true;
        }

        if ($allowRasterDataImage && preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,/i', $schemeCandidate) === 1) {
            return false;
        }

        return (bool) preg_match('/^(javascript|vbscript|data|file):/i', $schemeCandidate);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument?->saveHTML($childNode) ?: '';
        }

        return $html;
    }
}
