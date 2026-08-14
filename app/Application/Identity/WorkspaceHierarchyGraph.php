<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Workspace;
use App\Models\WorkspaceAncestry;
use Illuminate\Support\Facades\DB;

final readonly class WorkspaceHierarchyGraph
{
    private const int ADVISORY_LOCK_NAMESPACE = 1_095_789_895;
    private const int ADVISORY_LOCK_KEY = 1_752_132_459;

    public function acquireMutationLock(): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [self::ADVISORY_LOCK_NAMESPACE, self::ADVISORY_LOCK_KEY],
        );
    }

    /**
     * Rows from the selected workspace to itself and every descendant.
     *
     * @return list<WorkspaceAncestry>
     */
    public function subtreeRows(string $workspaceUid): array
    {
        return array_values(WorkspaceAncestry::query()
            ->where('ancestor_workspace_uid', $workspaceUid)
            ->orderBy('depth')
            ->orderBy('descendant_workspace_uid')
            ->get()
            ->all());
    }

    /**
     * Rows from the selected workspace to itself and each ancestor.
     *
     * @return list<WorkspaceAncestry>
     */
    public function ancestorRows(string $workspaceUid): array
    {
        return array_values(WorkspaceAncestry::query()
            ->where('descendant_workspace_uid', $workspaceUid)
            ->orderBy('depth')
            ->orderBy('ancestor_workspace_uid')
            ->get()
            ->all());
    }

    /**
     * @param list<WorkspaceAncestry> $subtreeRows
     * @param list<WorkspaceAncestry> $newParentAncestorRows
     */
    public function replaceParent(
        Workspace $workspace,
        ?string $newParentWorkspaceUid,
        array $subtreeRows,
        array $newParentAncestorRows,
    ): void {
        $subtreeWorkspaceUids = array_map(
            static fn (WorkspaceAncestry $row): string => $row->descendant_workspace_uid,
            $subtreeRows,
        );

        DB::table('workspace_ancestry')
            ->whereIn('descendant_workspace_uid', $subtreeWorkspaceUids)
            ->whereNotIn('ancestor_workspace_uid', $subtreeWorkspaceUids)
            ->delete();

        $workspace->forceFill([
            'parent_workspace_uid' => $newParentWorkspaceUid,
            // A root has no inheritance boundary. Normalize the persisted flag
            // now so a later attachment does not revive an obsolete opt-out.
            'inherits_parent_memberships' => $newParentWorkspaceUid !== null
                ? $workspace->inherits_parent_memberships
                : true,
        ])->save();

        if ($newParentWorkspaceUid === null) {
            return;
        }

        /** @var list<array{ancestor_workspace_uid: string, descendant_workspace_uid: string, depth: int}> $newRows */
        $newRows = [];

        foreach ($newParentAncestorRows as $ancestor) {
            foreach ($subtreeRows as $descendant) {
                $newRows[] = [
                    'ancestor_workspace_uid' => $ancestor->ancestor_workspace_uid,
                    'descendant_workspace_uid' => $descendant->descendant_workspace_uid,
                    'depth' => $ancestor->depth + 1 + $descendant->depth,
                ];
            }
        }

        if ($newRows !== []) {
            DB::table('workspace_ancestry')->insert($newRows);
        }
    }
}
