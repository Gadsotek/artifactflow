<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\Identity\WorkspaceRole;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single source of truth for an actor's effective role in a workspace on the
 * Identity side, mirroring PageAccess::workspaceRole() semantics: the MCP
 * effective authority narrows workspace reach and de-elevates admin to editor,
 * so MCP-authority-constrained requests can never exercise workspace-admin
 * capabilities through raw membership lookups.
 */
final readonly class WorkspaceAccess
{
    public function __construct(
        private McpEffectiveAuthority $mcpAuthority,
    ) {
    }

    public function role(string $actorUid, string $workspaceUid): ?WorkspaceRole
    {
        if (!$this->mcpAuthority->workspaceAllowed($workspaceUid)) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $actorUid)
            ->first();

        return $this->mcpAuthority->workspaceRole(
            $membership instanceof WorkspaceMembership ? $membership->role : null,
        );
    }

    public function isAdmin(string $actorUid, string $workspaceUid): bool
    {
        return $this->mcpAuthority->adminClassCapabilitiesAllowed()
            && $this->role($actorUid, $workspaceUid) === WorkspaceRole::Admin;
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureAdmin(string $actorUid, string $workspaceUid, string $message): void
    {
        if (!$this->isAdmin($actorUid, $workspaceUid)) {
            throw new AuthorizationException($message);
        }
    }
}
