<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;

final readonly class GrantPageAccessCommand
{
    public function __construct(
        public string $pageUid,
        public PageAccessSubjectType $subjectType,
        public string $subjectUid,
        public WorkspaceRole $role,
    ) {
    }
}
