<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use App\Models\McpClientSession;

final class McpRequestContext
{
    private ?string $accessTokenUid = null;

    private ?string $agentSessionId = null;

    private ?string $clientReportedName = null;

    private ?string $clientReportedVersion = null;

    /**
     * @var list<string>|null
     */
    private ?array $workspaceUids = null;

    public function activate(McpAccessToken $token, ?string $agentSessionId): void
    {
        $this->accessTokenUid = $token->uid;
        $this->agentSessionId = $this->normalizeAgentSessionId($agentSessionId);
        $clientSession = $this->clientSession($token);
        $this->clientReportedName = $clientSession?->client_reported_name;
        $this->clientReportedVersion = $clientSession?->client_reported_version;
        $this->workspaceUids = $token->workspaceUids();
    }

    public function clear(): void
    {
        $this->accessTokenUid = null;
        $this->agentSessionId = null;
        $this->clientReportedName = null;
        $this->clientReportedVersion = null;
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

    public function agentSessionId(): ?string
    {
        return $this->agentSessionId;
    }

    public function clientReportedName(): ?string
    {
        return $this->clientReportedName;
    }

    public function clientReportedVersion(): ?string
    {
        return $this->clientReportedVersion;
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

    private function clientSession(McpAccessToken $token): ?McpClientSession
    {
        if ($this->agentSessionId === null) {
            return null;
        }

        return McpClientSession::query()
            ->where('session_id_hash', hash('sha256', $this->agentSessionId))
            ->where('mcp_access_token_uid', $token->uid)
            ->first();
    }
}
