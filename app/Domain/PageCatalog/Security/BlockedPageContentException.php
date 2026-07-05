<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog\Security;

use App\Domain\DomainRuleViolation;

final class BlockedPageContentException extends DomainRuleViolation
{
    /**
     * @param list<string> $findingCodes
     */
    public function __construct(
        private readonly array $findingCodes,
    ) {
        parent::__construct('Page content contains an obvious secret.');
    }

    /**
     * @return list<string>
     */
    public function findingCodes(): array
    {
        return $this->findingCodes;
    }
}
