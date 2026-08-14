<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class WorkspaceMemberAuthorityRetirementResult
{
    public function __construct(
        public int $reassignedPageCount,
        public ?string $replacementOwnerUserUid,
        public int $revokedInvitationCount,
        public int $revokedPageAccessGrantCount,
        public int $invalidatedPreviewPageCount,
    ) {
    }
}
