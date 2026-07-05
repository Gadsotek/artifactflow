<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use App\Models\WorkspaceMembership;

final readonly class WorkspaceInvitationRegistrationResult
{
    public function __construct(
        public User $user,
        public WorkspaceMembership $membership,
    ) {
    }
}
