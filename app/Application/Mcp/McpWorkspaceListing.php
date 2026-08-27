<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\Mcp\Output\McpWorkspaceView;
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
     * @return list<McpWorkspaceView>
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
            ->map(static fn (Workspace $workspace): McpWorkspaceView => new McpWorkspaceView(
                uid: $workspace->uid,
                name: new McpUntrustedText($workspace->name),
            ))
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
