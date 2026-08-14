<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class EffectiveWorkspaceSettings
{
    /**
     * @param list<string> $inviteBlockingWorkspaceUids
     * @param list<string> $pageSharingBlockingWorkspaceUids
     */
    public function __construct(
        public string $workspaceUid,
        public bool $allowEditorInvites,
        public bool $allowEditorPageSharing,
        public array $inviteBlockingWorkspaceUids,
        public array $pageSharingBlockingWorkspaceUids,
    ) {
    }
}
