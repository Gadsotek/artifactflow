<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\User;

final class PageAccessGrantTargetResolver
{
    public const string TARGET_NOT_RESOLVED_MESSAGE = 'Access grant target could not be resolved.';

    public function resolve(
        PageAccessSubjectType $subjectType,
        ?string $userEmail,
        ?string $workspaceUid,
    ): string {
        if ($subjectType === PageAccessSubjectType::Workspace) {
            $normalizedWorkspaceUid = $workspaceUid === null ? '' : trim($workspaceUid);

            if ($normalizedWorkspaceUid === '') {
                throw new DomainRuleViolation('Workspace access grant target is required.');
            }

            return $normalizedWorkspaceUid;
        }

        $normalizedEmail = $userEmail === null ? '' : mb_strtolower(trim($userEmail));

        if ($normalizedEmail === '') {
            throw new DomainRuleViolation('User email is required.');
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if (!$user instanceof User) {
            throw new PageAccessGrantTargetUnavailable(self::TARGET_NOT_RESOLVED_MESSAGE);
        }

        return $user->uid;
    }
}
