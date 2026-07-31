<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\Security\BlockedPageContentException;

final readonly class PageVersionChangeSummaryRules
{
    public const int MAX_CHARACTERS = 255;

    public function __construct(
        private PageContentScanner $scanner,
    ) {
    }

    public function normalize(?string $changeSummary): ?string
    {
        if ($changeSummary === null) {
            return null;
        }

        $normalized = trim($changeSummary);

        if ($normalized === '') {
            return null;
        }

        if (!PageContentEncoding::isStorable($normalized)) {
            throw new DomainRuleViolation(
                'Version change summary must not contain control characters or invalid text.',
            );
        }

        if (mb_strlen($normalized) > self::MAX_CHARACTERS) {
            throw new DomainRuleViolation('Version change summary must be 255 characters or fewer.');
        }

        $scan = $this->scanner->scanDescription($normalized);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        return $normalized;
    }
}
