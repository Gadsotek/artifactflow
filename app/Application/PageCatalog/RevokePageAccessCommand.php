<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageAccessSubjectType;

final readonly class RevokePageAccessCommand
{
    public function __construct(
        public string $pageUid,
        public PageAccessSubjectType $subjectType,
        public string $subjectUid,
    ) {
    }
}
