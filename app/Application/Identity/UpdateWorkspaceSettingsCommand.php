<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class UpdateWorkspaceSettingsCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $name,
        public bool $allowEditorInvites,
        public bool $allowEditorPageSharing,
    ) {
    }
}
