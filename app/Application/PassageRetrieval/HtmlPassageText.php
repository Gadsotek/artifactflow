<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

/**
 * @phpstan-import-type ByteSpan from HtmlSourceDocument
 * @phpstan-import-type HtmlRole from SourcePassage
 * @phpstan-import-type HtmlRange from SourcePassage
 */
final readonly class HtmlPassageText
{
    /** @param list<ByteSpan> $spans */
    public function __construct(
        public string $text,
        public array $spans,
    ) {
    }

    /**
     * @param HtmlRole $role
     * @return list<HtmlRange>
     */
    public function ranges(string $role): array
    {
        return array_map(static fn (array $span): array => ['role' => $role] + $span, $this->spans);
    }
}
