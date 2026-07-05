<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions (or reuses) an Editor-scoped service account for the CLI
 * MCP-token workflow and issues a token, atomically. Keeps this use case out
 * of the console route file.
 */
final readonly class IssueCliMcpToken
{
    public function __construct(private McpAccessTokenIssuer $issuer)
    {
    }

    /**
     * @param list<string> $workspaceUids
     * @param list<string> $scopes
     */
    public function handle(string $email, string $name, array $workspaceUids, array $scopes, int $ttlDays): McpIssuedAccessToken
    {
        return DB::transaction(function () use ($email, $name, $workspaceUids, $scopes, $ttlDays): McpIssuedAccessToken {
            $serviceAccount = User::query()->where('email', $email)->first();

            if (!$serviceAccount instanceof User) {
                $serviceAccount = User::query()->forceCreate([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(64)),
                    'is_service_account' => true,
                    'two_factor_required' => false,
                ]);
            }

            if ($serviceAccount->is_system_admin || !$serviceAccount->is_service_account) {
                throw new DomainRuleViolation('MCP tokens can only be minted for non-admin service accounts.');
            }

            if (!McpAccessTokenIssuer::serviceAccountCanUseCli($serviceAccount)) {
                throw new DomainRuleViolation('MCP service accounts must not hold workspace Admin memberships.');
            }

            foreach ($workspaceUids as $workspaceUid) {
                $workspace = Workspace::query()->find($workspaceUid);

                if (!$workspace instanceof Workspace) {
                    throw new DomainRuleViolation(sprintf('Workspace [%s] does not exist.', $workspaceUid));
                }

                $membership = WorkspaceMembership::query()->firstOrNew([
                    'workspace_uid' => $workspace->uid,
                    'user_uid' => $serviceAccount->uid,
                ]);
                $membership->forceFill([
                    'role' => WorkspaceRole::Editor,
                    'accepted_at' => now(),
                ])->save();
            }

            return $this->issuer->issue(
                principal: $serviceAccount,
                name: 'MCP token for ' . $name,
                scopes: $scopes,
                expiresAt: now()->addDays($ttlDays),
                channel: 'cli',
                workspaceUids: $workspaceUids,
            );
        });
    }
}
