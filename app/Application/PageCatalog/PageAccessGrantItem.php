<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;

final readonly class PageAccessGrantItem
{
    public function __construct(
        public string $grantUid,
        public PageAccessSubjectType $subjectType,
        public string $subjectLabel,
        public WorkspaceRole $role,
    ) {
    }
}
