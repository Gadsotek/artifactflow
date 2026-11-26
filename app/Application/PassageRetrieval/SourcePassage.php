<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

/**
 * @phpstan-type MarkdownLocator array{kind: 'markdown_source', start_line: int, end_line: int, start_byte: int, end_byte: int}
 * @phpstan-type HtmlRole 'heading'|'row_header'|'column_header'|'body'
 * @phpstan-type HtmlRange array{role: HtmlRole, start_byte: int, end_byte: int}
 * @phpstan-type HtmlLocator array{kind: 'html_source_ranges', ranges: list<HtmlRange>}
 */
final readonly class SourcePassage
{
    /**
     * @param list<string> $headingPath
     * @param MarkdownLocator|HtmlLocator $sourceLocator
     */
    public function __construct(
        public array $headingPath,
        public string $excerpt,
        public array $sourceLocator,
    ) {
    }
}
