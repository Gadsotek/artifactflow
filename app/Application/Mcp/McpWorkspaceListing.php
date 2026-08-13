<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Workspace;

/**
 * Read query for the MCP list_workspaces tool: the workspaces an actor belongs
 * to, narrowed to the token's workspace scope.
 */
final readonly class McpWorkspaceListing
{
    public function __construct(
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    /**
     * @return list<array{uid: string, name: array{prompt_read_first: string, kind: string, media_type: string, data: string}}>
     */
    public function forActor(User $actor, McpAccessToken $token): array
    {
        $workspaceUids = $this->filterWorkspaceUidsForToken(
            $this->memberships->workspaceUidsFor($actor->uid),
            $token,
        );

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
