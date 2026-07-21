<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class McpAccessTokenRevoker
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function revoke(McpAccessToken $token, ?User $actor = null, string $channel = 'application'): bool
    {
        return DB::transaction(function () use ($token, $actor, $channel): bool {
            $lockedToken = McpAccessToken::query()
                ->whereKey($token->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedToken instanceof McpAccessToken || $lockedToken->revoked_at !== null) {
                return false;
            }

            $lockedToken->forceFill(['revoked_at' => now()])->save();
            $this->recordRevoked(
                token: $lockedToken,
                actorUserUid: $actor?->uid,
                channel: $channel,
                reason: 'manual',
            );

            return true;
        });
    }

    public function revokeActiveForPrincipal(
        User $principal,
        ?string $actorUserUid,
        string $channel,
        string $reason,
    ): int {
        return DB::transaction(function () use ($principal, $actorUserUid, $channel, $reason): int {
            $revokedAt = now();
            /** @var Collection<int, McpAccessToken> $tokens */
            $tokens = McpAccessToken::query()
                ->where('principal_user_uid', $principal->uid)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            if ($tokens->isEmpty()) {
                return 0;
            }

            /** @var list<string> $tokenUids */
            $tokenUids = $tokens->pluck('uid')->all();
            McpAccessToken::query()
                ->whereIn('uid', $tokenUids)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $revokedAt,
                    'updated_at' => $revokedAt,
                ]);

            foreach ($tokens as $token) {
                $token->forceFill(['revoked_at' => $revokedAt]);
                $this->recordRevoked(
                    token: $token,
                    actorUserUid: $actorUserUid,
                    channel: $channel,
                    reason: $reason,
                );
            }

            return $tokens->count();
        });
    }

    private function recordRevoked(
        McpAccessToken $token,
        ?string $actorUserUid,
        string $channel,
        string $reason,
    ): void {
        $payload = [
            'mcp_access_token_uid' => $token->uid,
            'principal_user_uid' => $token->principal_user_uid,
            'actor_user_uid' => $actorUserUid,
            'channel' => $channel,
            'reason' => $reason,
        ];
        $event = $this->events->record(
            eventType: DomainEventType::McpTokenRevoked,
            aggregateType: 'mcp_access_token',
            aggregateUid: $token->uid,
            payload: $payload,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUserUid,
            auditableType: 'mcp_access_token',
            auditableUid: $token->uid,
            action: DomainEventType::McpTokenRevoked,
            summary: 'MCP token revoked.',
            metadata: $payload,
        );
    }
}
