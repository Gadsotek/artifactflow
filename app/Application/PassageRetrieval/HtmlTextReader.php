<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use Dom\Element;
use Dom\Node;
use Dom\Text;
use Generator;

/**
 * @phpstan-import-type ByteSpan from HtmlSourceDocument
 */
final readonly class HtmlTextReader
{
    public function __construct(
        private string $source,
        private HtmlSourceDocument $document,
        private PassageCollector $collector,
    ) {
    }

    /** Headings and table labels have to be interpretable in their entirety. */
    public function single(Element $element): ?HtmlPassageText
    {
        $result = null;
        $blocks = $this->blocks([$element]);
        foreach ($blocks as $block) {
            if ($result !== null) {
                $this->collector->markLimited();

                return null;
            }
            $result = $block;
        }

        return $blocks->getReturn() ? $result : null;
    }

    /**
     * @param iterable<Node> $nodes
     * @return Generator<int, HtmlPassageText, void, bool>
     */
    public function blocks(iterable $nodes, int $maxRanges = PassageCollector::MAX_RANGES): Generator
    {
        $text = '';
        $spans = [];
        foreach ($nodes as $node) {
            foreach ($this->pieces($node) as $piece) {
                if ($piece === false) {
                    return false;
                }
                [$value, $span] = $piece;
                if ($text === '' || str_ends_with($text, ' ')) {
                    $value = ltrim($value, ' ');
                }
                if ($span === null) {
                    if (strlen($text) < PassageCollector::MAX_EXCERPT_BYTES) {
                        $text .= $value;
                    }
                    continue;
                }
                if (strlen($text) + strlen($value) > PassageCollector::MAX_EXCERPT_BYTES || count($spans) >= $maxRanges) {
                    if (trim($text) !== '' && $spans !== []) {
                        yield new HtmlPassageText(trim($text), $spans);
                    }
                    $text = '';
                    $spans = [];
                }
                $text .= $value;
                $spans[] = $span;
            }
        }

        if (trim($text) !== '' && $spans !== []) {
            yield new HtmlPassageText(trim($text), $spans);
        }
        return true;
    }

    /** @return Generator<int, array{string, ByteSpan|null}|false> */
    private function pieces(Node $node): Generator
    {
        if (!$this->collector->advance()) {
            yield false;

            return;
        }
        if ($node instanceof Text) {
            $span = $this->document->span($node);
            if ($span === null) {
                if (trim($node->data) === '') {
                    yield [' ', null];
                } else {
                    $this->collector->markLimited();
                    yield false;
                }
                return;
            }
            yield from $this->textPieces($node->data, $span);

            return;
        }
        if (!$node instanceof Element || HtmlSourceDocument::excluded($node)) {
            return;
        }
        if ($node->localName === 'br') {
            yield [' ', null];

            return;
        }

        // Block boundaries are spacing in the excerpt, with no source span.
        $block = HtmlSourceDocument::isBlock($node);
        if ($block) {
            yield [' ', null];
        }
        foreach ($node->childNodes as $child) {
            yield from $this->pieces($child);
        }
        if ($block) {
            yield [' ', null];
        }
    }

    /**
     * @param ByteSpan $span
     * @return Generator<int, array{string, ByteSpan}|false>
     */
    private function textPieces(string $text, array $span): Generator
    {
        if (strlen($text) <= PassageCollector::MAX_EXCERPT_BYTES) {
            yield [preg_replace('/\s+/u', ' ', $text) ?? $text, $span];

            return;
        }

        // Map long text incrementally. Each raw slice must decode to the
        // corresponding part of the HTML5 text node before its offsets can ship.
        $decodedOffset = 0;
        $start = $span['start_byte'];
        while ($start < $span['end_byte'] && $this->collector->advance()) {
            $end = min($start + PassageCollector::MAX_EXCERPT_BYTES, $span['end_byte']);
            if ($end < $span['end_byte']) {
                while ((ord($this->source[$end]) & 0xc0) === 0x80) {
                    $end--;
                }
                if ($this->source[$end - 1] === "\r" && $this->source[$end] === "\n") {
                    $end--;
                }
            }
            $raw = substr($this->source, $start, $end - $start);
            if ($end < $span['end_byte'] && preg_match('/&(?:#[xX]?[0-9a-fA-F]*|[a-zA-Z][a-zA-Z0-9]*)?$/D', $raw, $tail, PREG_OFFSET_CAPTURE) === 1) {
                $end = $start + $tail[0][1];
                $raw = substr($raw, 0, $tail[0][1]);
            }

            $decoded = html_entity_decode(str_replace(["\r\n", "\r"], "\n", $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === '' || substr($text, $decodedOffset, strlen($decoded)) !== $decoded) {
                $this->collector->markLimited();
                yield false;

                return;
            }
            yield [preg_replace('/\s+/u', ' ', $decoded) ?? $decoded, ['start_byte' => $start, 'end_byte' => $end]];
            $decodedOffset += strlen($decoded);
            $start = $end;
        }
    }
}
