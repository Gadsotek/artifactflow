<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Read query for the MCP list_workspaces tool: the workspaces an actor belongs
 * to, narrowed to the token's workspace scope. Extracted from McpController so
 * the controller no longer builds membership/workspace queries inline.
 */
final readonly class McpWorkspaceListing
{
    /**
     * @return list<array{uid: string, name: array{prompt_read_first: string, kind: string, media_type: string, data: string}}>
     */
    public function forActor(User $actor, McpAccessToken $token): array
    {
        $membershipUids = WorkspaceMembership::query()
            ->where('user_uid', $actor->uid)
            ->get(['workspace_uid'])
            ->map(static fn (WorkspaceMembership $membership): string => $membership->workspace_uid)
            ->values()
            ->all();

        $workspaceUids = $this->filterWorkspaceUidsForToken(array_values($membershipUids), $token);

        return array_values(Workspace::query()
            ->whereIn('uid', $workspaceUids)
            ->orderBy('name')
            ->get(['uid', 'name'])
            ->map(static fn (Workspace $workspace): array => [
                'uid' => $workspace->uid,
                'name' => McpDataEnvelope::text($workspace->name),
            ])
            ->all());
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<string>
     */
    private function filterWorkspaceUidsForToken(array $workspaceUids, McpAccessToken $token): array
    {
        $tokenWorkspaceUids = $token->workspaceUids();
        $uniqueWorkspaceUids = array_values(array_unique($workspaceUids));

        if ($tokenWorkspaceUids === null) {
            return $uniqueWorkspaceUids;
        }

        return array_values(array_intersect($uniqueWorkspaceUids, $tokenWorkspaceUids));
    }
}
