<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\Identity\WorkspaceRole;

final readonly class McpEffectiveAuthority
{
    public function __construct(
        private McpRequestContext $context,
    ) {
    }

    public function isActive(): bool
    {
        return $this->context->isActive();
    }

    public function cacheKeyPrefix(): string
    {
        $accessTokenUid = $this->context->accessTokenUid();

        return $accessTokenUid === null ? 'browser:' : 'mcp:' . $accessTokenUid . ':';
    }

    public function workspaceRole(?WorkspaceRole $role): ?WorkspaceRole
    {
        return $this->deElevatedRole($role);
    }

    public function workspaceAllowed(string $workspaceUid): bool
    {
        $workspaceUids = $this->context->workspaceUids();

        return $workspaceUids === null || in_array($workspaceUid, $workspaceUids, true);
    }

    /**
     * @return list<string>|null
     */
    public function workspaceScopeUids(): ?array
    {
        return $this->context->workspaceUids();
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<string>
     */
    public function filterWorkspaceUids(array $workspaceUids): array
    {
        $scopeUids = $this->context->workspaceUids();

        if ($scopeUids === null) {
            return $workspaceUids;
        }

        return array_values(array_intersect($workspaceUids, $scopeUids));
    }

    public function pageGrantRole(?WorkspaceRole $role): ?WorkspaceRole
    {
        return $this->deElevatedRole($role);
    }

    public function adminClassCapabilitiesAllowed(): bool
    {
        return !$this->isActive();
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<string>
     */
    public function adminWorkspaceUids(array $workspaceUids): array
    {
        return $this->isActive() ? [] : $workspaceUids;
    }

    /**
     * @param list<string> $workspaceUids
     */
    public function canExposeWorkspaceName(string $workspaceUid, array $workspaceUids): bool
    {
        return $this->workspaceAllowed($workspaceUid)
            && in_array($workspaceUid, $workspaceUids, true);
    }

    private function deElevatedRole(?WorkspaceRole $role): ?WorkspaceRole
    {
        if ($this->isActive() && $role === WorkspaceRole::Admin) {
            return WorkspaceRole::Editor;
        }

        return $role;
    }
}
