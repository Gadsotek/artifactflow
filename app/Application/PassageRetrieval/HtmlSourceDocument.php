<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use SplObjectStorage;

/**
 * @phpstan-type ByteSpan array{start_byte: int, end_byte: int}
 */
final readonly class HtmlSourceDocument
{
    /** @param SplObjectStorage<Text, ByteSpan> $spans */
    private function __construct(
        public Element $body,
        private SplObjectStorage $spans,
    ) {
    }

    public static function parse(string $source, PassageCollector $collector): ?self
    {
        $marker = 'AF' . bin2hex(random_bytes(12)) . ':';
        $annotated = (new HtmlSourceOffsets())->annotate($source, $marker, $collector);
        if ($annotated === null || !$collector->advance()) {
            return null;
        }

        $document = HTMLDocument::createFromString($annotated, LIBXML_NOERROR, 'UTF-8');
        /** @var SplObjectStorage<Text, ByteSpan> $spans */
        $spans = new SplObjectStorage();
        if (!self::recoverSpans($document, $marker, $spans, $collector, 0)) {
            return null;
        }

        $original = HTMLDocument::createFromString($source, LIBXML_NOERROR, 'UTF-8');
        if (!$document->isEqualNode($original) || !$document->body instanceof Element) {
            return null;
        }

        return new self($document->body, $spans);
    }

    /** @return ByteSpan|null */
    public function span(Text $node): ?array
    {
        return $this->spans->offsetExists($node) ? $this->spans[$node] : null;
    }

    public static function isBlock(Element $element): bool
    {
        return in_array($element->localName, [
            'address', 'article', 'aside', 'blockquote', 'body', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset',
            'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li',
            'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'ul',
        ], true);
    }

    public static function excluded(Element $element): bool
    {
        return $element->namespaceURI !== 'http://www.w3.org/1999/xhtml'
            || in_array($element->localName, [
                'head', 'script', 'style', 'template', 'noscript', 'title', 'textarea', 'iframe', 'object', 'embed',
                'canvas', 'select', 'xmp', 'noembed', 'noframes', 'plaintext',
            ], true)
            || $element->hasAttribute('hidden')
            || $element->hasAttribute('inert')
            || strtolower(trim($element->getAttribute('aria-hidden') ?? '')) === 'true'
            || preg_match('/(?:^|;)\s*(?:display\s*:\s*none|(?:content-)?visibility\s*:\s*(?:hidden|collapse))\b/i', $element->getAttribute('style') ?? '') === 1;
    }

    /**
     * @param SplObjectStorage<Text, ByteSpan> $spans
     */
    private static function recoverSpans(Node $node, string $marker, SplObjectStorage $spans, PassageCollector $collector, int $depth): bool
    {
        if ($depth > PassageCollector::MAX_DEPTH || !$collector->advance()) {
            return false;
        }

        if ($node instanceof Text && str_starts_with($node->data, $marker)) {
            if (preg_match('/^' . $marker . '([0-9]+):([0-9]+):/', $node->data, $match) !== 1) {
                return false;
            }
            $spans[$node] = ['start_byte' => (int) $match[1], 'end_byte' => (int) $match[2]];
            $node->data = substr($node->data, strlen($match[0]));
        }

        foreach ($node->childNodes as $child) {
            if (!self::recoverSpans($child, $marker, $spans, $collector, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
