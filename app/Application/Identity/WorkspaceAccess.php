<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Mcp\McpEffectiveAuthority;
use App\Domain\Identity\WorkspaceRole;
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
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    public function role(string $actorUid, string $workspaceUid): ?WorkspaceRole
    {
        if (!$this->mcpAuthority->workspaceAllowed($workspaceUid)) {
            return null;
        }

        return $this->mcpAuthority->workspaceRole(
            $this->memberships->resolve($actorUid, $workspaceUid)->role,
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
