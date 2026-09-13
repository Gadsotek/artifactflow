<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Administration\InstallationLimitCeilings;
use App\Application\Administration\InstallationLimitSettings;
use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Mcp\StaleMcpAuthenticationRevision;
use App\Domain\PageCatalog\PageContentEncoding;
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
    public const string SCOPE_ORGANIZE = 'mcp:organize';
    public const string SCOPE_UPLOAD = 'mcp:upload';
    public const string SCOPE_SHARE = 'mcp:share';

    /**
     * @var list<string>
     */
    private const array ALLOWED_SCOPES = [
        self::SCOPE_SEARCH,
        self::SCOPE_READ,
        self::SCOPE_CREATE,
        self::SCOPE_UPDATE,
        self::SCOPE_ORGANIZE,
        self::SCOPE_UPLOAD,
        self::SCOPE_SHARE,
    ];

    /**
     * @var list<string>
     */
    private const array WRITE_SCOPES = [
        self::SCOPE_CREATE,
        self::SCOPE_UPDATE,
        self::SCOPE_ORGANIZE,
        self::SCOPE_UPLOAD,
        self::SCOPE_SHARE,
    ];

    /**
     * Default write-token limit. System admins may change it up to the absolute
     * ceiling; issuance always uses the current installation settings.
     */
    public const int DEFAULT_WRITE_SCOPE_TTL_DAYS = 90;

    /**
     * Absolute ceiling for admin-configured limits, regardless of scope.
     */
    public const int MAX_TOKEN_TTL_DAYS = InstallationLimitCeilings::MCP_TOKEN_TTL_DAYS;

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
        private InstallationLimitSettings $settings,
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
        ?int $expectedAuthRevision = null,
    ): McpIssuedAccessToken {
        $normalizedScopes = $this->normalizeScopes($scopes);
        $normalizedWorkspaceUids = $workspaceUids === null ? null : $this->normalizeWorkspaceUids($workspaceUids);

        if ($normalizedScopes === []) {
            throw new DomainRuleViolation('At least one MCP scope is required.');
        }

        if ($expiresAt->isPast()) {
            throw new DomainRuleViolation('MCP token expiry must be in the future.');
        }

        // Enforce the installation policy at the shared issuance boundary so
        // CLI and application callers cannot bypass the self-service limits.
        $maximumDays = $this->maximumTtlDays($normalizedScopes);
        if ($expiresAt->greaterThan(Carbon::now()->addDays($maximumDays))) {
            throw new DomainRuleViolation(sprintf(
                self::includesWriteScope($normalizedScopes)
                    ? 'Write-capable MCP tokens must expire within %d days.'
                    : 'MCP tokens must expire within %d days.',
                $maximumDays,
            ));
        }

        $normalizedName = trim($name) === '' ? 'MCP token' : mb_substr(trim($name), 0, 120);

        if (!PageContentEncoding::isStorable($normalizedName)) {
            throw new DomainRuleViolation('MCP token name must not contain control characters or invalid text.');
        }

        $plainTextToken = 'af_mcp_' . Str::random(64);

        // Same-transaction durability: the token row and its domain-event + audit
        // journal entries commit together (a savepoint when the CLI path already
        // opened a transaction), so a mid-write failure never leaves a live
        // credential without a traceability record.
        $token = DB::transaction(function () use (
            $plainTextToken,
            $principal,
            $normalizedName,
            $normalizedScopes,
            $normalizedWorkspaceUids,
            $expiresAt,
            $actor,
            $channel,
            $expectedAuthRevision,
        ): McpAccessToken {
            $lockedPrincipal = User::query()
                ->whereKey($principal->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedPrincipal instanceof User) {
                throw new DomainRuleViolation('MCP token principal no longer exists.');
            }

            if (
                $expectedAuthRevision !== null
                && $lockedPrincipal->auth_revision !== $expectedAuthRevision
            ) {
                throw new StaleMcpAuthenticationRevision(
                    'Authentication changed while the MCP token was being created.',
                );
            }

            $this->ensurePrincipalCanHoldMcpToken($lockedPrincipal);

            $token = McpAccessToken::query()->forceCreate([
                'principal_user_uid' => $lockedPrincipal->uid,
                'name' => $normalizedName,
                'token_hash' => self::hashToken($plainTextToken),
                'scopes' => $normalizedScopes,
                'workspace_uids' => $normalizedWorkspaceUids,
                'expires_at' => $expiresAt,
            ]);
            $this->recordIssued($token, $lockedPrincipal, $actor, $channel);

            return $token;
        });

        return new McpIssuedAccessToken($token, $plainTextToken);
    }

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /** @param list<string> $scopes */
    public function maximumTtlDays(array $scopes): int
    {
        $settings = $this->settings->current();

        return self::includesWriteScope($scopes)
            ? $settings->mcpWriteTokenMaxTtlDays
            : $settings->mcpReadTokenMaxTtlDays;
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
