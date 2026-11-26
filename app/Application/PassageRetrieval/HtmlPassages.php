<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use Dom\Element;
use Dom\Node;
use Dom\Text;

/** @phpstan-import-type HtmlRange from SourcePassage */
final class HtmlPassages
{
    /** @var array<int, HtmlPassageText> */
    private array $headings = [];

    private ?HtmlPassageText $pending = null;
    private bool $headingOnly = false;

    /** @var list<Node> */
    private array $inline = [];

    public function collect(string $source, PassageCollector $collector): void
    {
        $document = HtmlSourceDocument::parse($source, $collector);
        if ($document === null) {
            $collector->markLimited();

            return;
        }
        $html = $document->body->parentElement;
        if ($html instanceof Element && HtmlSourceDocument::excluded($html)) {
            return;
        }

        $reader = new HtmlTextReader($source, $document, $collector);
        $this->walk($document->body, $reader, $collector);
        $this->finishSection($collector);
    }

    private function walk(Node $element, HtmlTextReader $reader, PassageCollector $collector): void
    {
        if (!$collector->advance()) {
            return;
        }
        if ($element instanceof Text) {
            $this->inline[] = $element;

            return;
        }
        if (!$element instanceof Element || HtmlSourceDocument::excluded($element)) {
            return;
        }
        if (preg_match('/^h([1-6])$/D', $element->localName, $match) === 1) {
            $this->endBlock($reader, $collector);
            $this->flush($collector);
            $level = (int) $match[1];
            if ($this->headingOnly && $level <= array_key_last($this->headings)) {
                $this->finishSection($collector);
            }
            $this->headings = array_filter($this->headings, static fn (int $key): bool => $key < $level, ARRAY_FILTER_USE_KEY);
            $heading = $reader->single($element);
            $this->headingOnly = $heading !== null;
            if ($heading !== null) {
                $this->headings[$level] = $heading;
            }

            return;
        }
        if ($element->localName === 'table') {
            $this->endBlock($reader, $collector);
            $this->flush($collector);
            foreach ((new HtmlTablePassages())->cells($element, $reader, $collector) as [$row, $column, $value]) {
                $this->emit(
                    $row->text . ' · ' . $column->text . ' · ' . $value->text,
                    [...$row->ranges('row_header'), ...$column->ranges('column_header'), ...$value->ranges('body')],
                    $collector,
                );
            }

            return;
        }

        if ($element->localName === 'br') {
            $this->inline[] = $element;

            return;
        }
        $block = in_array($element->localName, [
            'address', 'article', 'aside', 'blockquote', 'body', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset',
            'figcaption', 'figure', 'footer', 'form', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
            'section', 'summary', 'ul',
        ], true);
        $section = in_array($element->localName, ['section', 'article'], true);
        $parentHeadings = $this->headings;
        if ($block) {
            $this->endBlock($reader, $collector);
        }
        if ($section) {
            $this->flush($collector);
        }
        foreach ($element->childNodes as $child) {
            $this->walk($child, $reader, $collector);
        }
        if ($block) {
            $this->endBlock($reader, $collector);
        }
        if ($section) {
            if ($this->headings !== $parentHeadings) {
                $this->finishSection($collector);
                $this->headingOnly = false;
            } else {
                $this->flush($collector);
            }
            $this->headings = $parentHeadings;
        }
    }

    private function endBlock(HtmlTextReader $reader, PassageCollector $collector): void
    {
        $nodes = $this->inline;
        $this->inline = [];
        if ($nodes === []) {
            return;
        }
        $availableRanges = PassageCollector::MAX_RANGES - count($this->headingRanges());
        if ($availableRanges < 1) {
            $collector->markLimited();

            return;
        }
        $blocks = $reader->blocks($nodes, $availableRanges);
        foreach ($blocks as $block) {
            $pending = $this->pending;
            if ($pending !== null
                && strlen($pending->text) + 1 + strlen($block->text) <= PassageCollector::MAX_EXCERPT_BYTES
                && count($this->headingRanges()) + count($pending->spans) + count($block->spans) <= PassageCollector::MAX_RANGES) {
                $this->pending = new HtmlPassageText($pending->text . "\n" . $block->text, [...$pending->spans, ...$block->spans]);
            } else {
                $this->flush($collector);
                $this->pending = $block;
            }
        }
        if (!$blocks->getReturn()) {
            $this->flush($collector);
        }
    }

    private function finishSection(PassageCollector $collector): void
    {
        $this->flush($collector);
        if ($this->headingOnly && $this->headings !== []) {
            $heading = $this->headings[array_key_last($this->headings)];
            $this->emit($heading->text, [], $collector);
        }
    }

    private function flush(PassageCollector $collector): void
    {
        if ($this->pending !== null) {
            $this->emit($this->pending->text, $this->pending->ranges('body'), $collector);
            $this->pending = null;
        }
    }

    /**
     * @param list<HtmlRange> $ranges
     */
    private function emit(string $excerpt, array $ranges, PassageCollector $collector): void
    {
        $ranges = [...$this->headingRanges(), ...$ranges];
        if (strlen($excerpt) > PassageCollector::MAX_EXCERPT_BYTES || count($ranges) > PassageCollector::MAX_RANGES) {
            $collector->markLimited();

            return;
        }
        $this->headingOnly = false;
        $collector->add(new SourcePassage(
            array_column($this->headings, 'text'),
            $excerpt,
            ['kind' => 'html_source_ranges', 'ranges' => $ranges],
        ));
    }

    /** @return list<HtmlRange> */
    private function headingRanges(): array
    {
        $ranges = [];
        foreach ($this->headings as $heading) {
            array_push($ranges, ...$heading->ranges('heading'));
        }

        return $ranges;
    }
}
