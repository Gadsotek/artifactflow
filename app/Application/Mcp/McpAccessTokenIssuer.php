<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class McpAccessTokenIssuer
{
    public const string SCOPE_SEARCH = 'mcp:search';
    public const string SCOPE_READ = 'mcp:read';
    public const string SCOPE_CREATE = 'mcp:create';
    public const string SCOPE_UPDATE = 'mcp:update';

    /**
     * @var list<string>
     */
    private const array ALLOWED_SCOPES = [
        self::SCOPE_SEARCH,
        self::SCOPE_READ,
        self::SCOPE_CREATE,
        self::SCOPE_UPDATE,
    ];

    /**
     * @var list<string>
     */
    private const array WRITE_SCOPES = [
        self::SCOPE_CREATE,
        self::SCOPE_UPDATE,
    ];

    /**
     * Maximum lifetime, in days, for a token that carries any write scope. A
     * standing read/write credential for an autonomous agent should have a much
     * shorter exposure window than a read-only one.
     */
    public const int MAX_WRITE_SCOPE_TTL_DAYS = 90;

    /**
     * Absolute maximum lifetime, in days, for any MCP token regardless of scope.
     * Even a read-only token grants standing, cross-workspace-scoped read access
     * to artifact content, so it must rotate rather than live indefinitely; a
     * CLI-minted `mcp:read`/`mcp:search` token was previously unbounded. Write
     * tokens are held to the much tighter MAX_WRITE_SCOPE_TTL_DAYS above.
     */
    public const int MAX_TOKEN_TTL_DAYS = 365;

    /**
     * @param list<string> $scopes
     */
    public static function includesWriteScope(array $scopes): bool
    {
        return array_intersect($scopes, self::WRITE_SCOPES) !== [];
    }

    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param list<string>|null $workspaceUids
     */
    public function issue(
        User $principal,
        string $name,
        array $scopes,
        Carbon $expiresAt,
        ?User $actor = null,
        string $channel = 'application',
        ?array $workspaceUids = null,
    ): McpIssuedAccessToken {
        $this->ensurePrincipalCanHoldMcpToken($principal);
        $normalizedScopes = $this->normalizeScopes($scopes);
        $normalizedWorkspaceUids = $workspaceUids === null ? null : $this->normalizeWorkspaceUids($workspaceUids);

        if ($normalizedScopes === []) {
            throw new DomainRuleViolation('At least one MCP scope is required.');
        }

        if ($expiresAt->isPast()) {
            throw new DomainRuleViolation('MCP token expiry must be in the future.');
        }

        // A write-capable token is a standing agent credential; cap its lifetime here,
        // in the one issuance chokepoint every entrypoint calls, so the CLI and any
        // future caller inherit the same limit the self-service UI enforces. Keeping
        // this only in the HTTP controller let `artifactflow:mcp-token-create` mint
        // effectively unbounded write tokens.
        if (
            self::includesWriteScope($normalizedScopes)
            && $expiresAt->greaterThan(Carbon::now()->addDays(self::MAX_WRITE_SCOPE_TTL_DAYS))
        ) {
            throw new DomainRuleViolation(sprintf(
                'Write-capable MCP tokens must expire within %d days.',
                self::MAX_WRITE_SCOPE_TTL_DAYS,
            ));
        }

        // Absolute ceiling for every token, so a read-only CLI token cannot be
        // minted with an effectively unbounded lifetime.
        if ($expiresAt->greaterThan(Carbon::now()->addDays(self::MAX_TOKEN_TTL_DAYS))) {
            throw new DomainRuleViolation(sprintf(
                'MCP tokens must expire within %d days.',
                self::MAX_TOKEN_TTL_DAYS,
            ));
        }

        $plainTextToken = 'af_mcp_' . Str::random(64);

        // Same-transaction durability: the token row and its domain-event + audit
        // journal entries commit together (a savepoint when the CLI path already
        // opened a transaction), so a mid-write failure never leaves a live
        // credential without a traceability record.
        $token = DB::transaction(function () use (
            $plainTextToken,
            $principal,
            $name,
            $normalizedScopes,
            $normalizedWorkspaceUids,
            $expiresAt,
            $actor,
            $channel,
        ): McpAccessToken {
            $token = McpAccessToken::query()->forceCreate([
                'principal_user_uid' => $principal->uid,
                'name' => trim($name) === '' ? 'MCP token' : mb_substr(trim($name), 0, 120),
                'token_hash' => self::hashToken($plainTextToken),
                'scopes' => $normalizedScopes,
                'workspace_uids' => $normalizedWorkspaceUids,
                'expires_at' => $expiresAt,
            ]);
            $this->recordIssued($token, $principal, $actor, $channel);

            return $token;
        });

        return new McpIssuedAccessToken($token, $plainTextToken);
    }

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * @return list<string>
     */
    public static function allowedScopes(): array
    {
        return self::ALLOWED_SCOPES;
    }

    public static function principalCanUseMcp(User $principal): bool
    {
        return $principal->is_service_account || $principal->hasEnabledTwoFactor();
    }

    public static function serviceAccountCanUseCli(User $principal): bool
    {
        if (!$principal->is_service_account || $principal->is_system_admin) {
            return false;
        }

        return !WorkspaceMembership::query()
            ->where('user_uid', $principal->uid)
            ->where('role', WorkspaceRole::Admin)
            ->exists();
    }

    private function ensurePrincipalCanHoldMcpToken(User $principal): void
    {
        if ($principal->is_service_account) {
            return;
        }

        if (!$principal->hasEnabledTwoFactor()) {
            throw new DomainRuleViolation('Human accounts must enable two-factor authentication before minting MCP tokens.');
        }
    }

    /**
     * @param list<mixed> $scopes
     *
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }

            $scope = trim($scope);

            if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
                throw new DomainRuleViolation(sprintf('Unsupported MCP scope [%s].', $scope));
            }

            $normalized[] = $scope;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<mixed> $workspaceUids
     *
     * @return list<string>
     */
    private function normalizeWorkspaceUids(array $workspaceUids): array
    {
        $normalized = [];

        foreach ($workspaceUids as $workspaceUid) {
            if (!is_string($workspaceUid)) {
                continue;
            }

            $workspaceUid = trim($workspaceUid);

            if ($workspaceUid !== '') {
                $normalized[] = $workspaceUid;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function recordIssued(McpAccessToken $token, User $principal, ?User $actor, string $channel): void
    {
        $workspaceUids = $token->workspaceUids();
        $payload = [
            'mcp_access_token_uid' => $token->uid,
            'principal_user_uid' => $principal->uid,
            'actor_user_uid' => $actor?->uid,
            'name' => $token->name,
            'scopes' => implode(',', $token->scopes),
            'workspace_uids' => $workspaceUids === null ? 'all' : implode(',', $workspaceUids),
            'expires_at' => $token->expires_at->toISOString(),
            'channel' => $channel,
        ];
        $event = $this->events->record(
            eventType: DomainEventType::McpTokenCreated,
            aggregateType: 'mcp_access_token',
            aggregateUid: $token->uid,
            payload: $payload,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actor?->uid,
            auditableType: 'mcp_access_token',
            auditableUid: $token->uid,
            action: DomainEventType::McpTokenCreated,
            summary: 'MCP token created.',
            metadata: $payload,
        );
    }
}
