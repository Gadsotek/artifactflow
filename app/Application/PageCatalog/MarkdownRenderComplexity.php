<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;

/**
 * A linear preflight for CommonMark's inline delimiter work. Source byte limits
 * alone do not bound parser memory: a small-enough run of unmatched/nested link
 * delimiters can build a disproportionately large internal delimiter stack.
 */
final readonly class MarkdownRenderComplexity
{
    private const int MAX_LINK_DELIMITERS = 50_000;

    public function ensureSafe(string $markdown): void
    {
        $linkDelimiters = substr_count($markdown, '[') + substr_count($markdown, ']');

        if ($linkDelimiters > self::MAX_LINK_DELIMITERS) {
            throw new DomainRuleViolation('Markdown content exceeds safe rendering complexity limits.');
        }
    }
}
