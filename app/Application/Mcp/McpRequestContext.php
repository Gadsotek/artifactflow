<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;

final class McpRequestContext
{
    private ?string $accessTokenUid = null;

    private ?string $agentSessionId = null;

    /**
     * @var list<string>|null
     */
    private ?array $workspaceUids = null;

    public function activate(McpAccessToken $token, ?string $agentSessionId): void
    {
        $this->accessTokenUid = $token->uid;
        $this->agentSessionId = $this->normalizeAgentSessionId($agentSessionId);
        $this->workspaceUids = $token->workspaceUids();
    }

    public function clear(): void
    {
        $this->accessTokenUid = null;
        $this->agentSessionId = null;
        $this->workspaceUids = null;
    }

    public function isActive(): bool
    {
        return $this->accessTokenUid !== null;
    }

    public function accessTokenUid(): ?string
    {
        return $this->accessTokenUid;
    }

    /**
     * @return list<string>|null
     */
    public function workspaceUids(): ?array
    {
        return $this->workspaceUids;
    }

    /**
     * @return array<string, string>
     */
    public function auditMetadata(): array
    {
        if ($this->accessTokenUid === null) {
            return [];
        }

        $metadata = [
            'mcp_access_token_uid' => $this->accessTokenUid,
        ];

        if ($this->agentSessionId !== null) {
            $metadata['mcp_agent_session_id'] = $this->agentSessionId;
        }

        return $metadata;
    }

    private function normalizeAgentSessionId(?string $agentSessionId): ?string
    {
        if ($agentSessionId === null) {
            return null;
        }

        $normalized = trim($agentSessionId);

        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^\w.:-]+/', '_', $normalized);

        if ($normalized === null) {
            return null;
        }

        return mb_substr($normalized, 0, 120);
    }
}
