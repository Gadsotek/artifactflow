<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Workspace;
use App\Models\WorkspaceAncestry;

final readonly class EffectiveWorkspaceSettingsResolver
{
    public function resolve(string $workspaceUid): EffectiveWorkspaceSettings
    {
        return $this->resolveInternal($workspaceUid, false);
    }

    /**
     * Locks the target and every ancestor in UID order. Callers must already
     * hold any affected page row lock, preserving the page-to-workspace order.
     */
    public function resolveLocked(string $workspaceUid): EffectiveWorkspaceSettings
    {
        return $this->resolveInternal($workspaceUid, true);
    }

    private function resolveInternal(string $workspaceUid, bool $lock): EffectiveWorkspaceSettings
    {
        /** @var list<string> $ancestorWorkspaceUids */
        $ancestorWorkspaceUids = WorkspaceAncestry::query()
            ->where('descendant_workspace_uid', $workspaceUid)
            ->pluck('ancestor_workspace_uid')
            ->all();
        sort($ancestorWorkspaceUids);

        if ($lock) {
            foreach ($ancestorWorkspaceUids as $ancestorWorkspaceUid) {
                Workspace::query()->whereKey($ancestorWorkspaceUid)->lockForUpdate()->first();
            }
        }

        $workspaces = Workspace::query()
            ->whereIn('uid', $ancestorWorkspaceUids)
            ->orderBy('uid')
            ->get();
        $inviteBlockingWorkspaceUids = [];
        $pageSharingBlockingWorkspaceUids = [];

        foreach ($workspaces as $workspace) {
            if (!$workspace->allow_editor_invites) {
                $inviteBlockingWorkspaceUids[] = $workspace->uid;
            }

            if (!$workspace->allow_editor_page_sharing) {
                $pageSharingBlockingWorkspaceUids[] = $workspace->uid;
            }
        }

        return new EffectiveWorkspaceSettings(
            workspaceUid: $workspaceUid,
            allowEditorInvites: $workspaces->isNotEmpty() && $inviteBlockingWorkspaceUids === [],
            allowEditorPageSharing: $workspaces->isNotEmpty() && $pageSharingBlockingWorkspaceUids === [],
            inviteBlockingWorkspaceUids: $inviteBlockingWorkspaceUids,
            pageSharingBlockingWorkspaceUids: $pageSharingBlockingWorkspaceUids,
        );
    }
}
