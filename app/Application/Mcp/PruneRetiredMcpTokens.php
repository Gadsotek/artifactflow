<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Reaps MCP access tokens once they have been retired -- revoked or naturally
 * expired -- for longer than the retention window. The window keeps a recently
 * killed token visible in settings history ("Revoked 2 days ago") before the
 * dead credential row is removed. A retired token can never authenticate again
 * (McpAccessTokenAuthenticator rejects revoked/expired tokens), so this only
 * trims history, never live access.
 */
final readonly class PruneRetiredMcpTokens
{
    public const int DEFAULT_DELETE_CHUNK_SIZE = 500;

    public function handle(
        int $retentionDays,
        bool $dryRun = false,
        int $chunkSize = self::DEFAULT_DELETE_CHUNK_SIZE,
    ): int {
        $retentionDays = max(0, $retentionDays);
        $chunkSize = max(1, $chunkSize);
        $cutoff = Carbon::now()->subDays($retentionDays);

        if ($dryRun) {
            return $this->prunable($cutoff)->count();
        }

        $prunedCount = 0;

        do {
            $deletedCount = $this->prunable($cutoff)
                ->limit($chunkSize)
                ->toBase()
                ->delete();
            $prunedCount += $deletedCount;
        } while ($deletedCount === $chunkSize);

        return $prunedCount;
    }

    /**
     * @return Builder<McpAccessToken>
     */
    private function prunable(Carbon $cutoff): Builder
    {
        // COALESCE picks the moment the token died: revocation when present, otherwise
        // its natural expiry. A live token (revoked_at null, expires_at in the future)
        // never matches because that future expiry is past the cutoff.
        return McpAccessToken::query()
            ->whereRaw('coalesce(revoked_at, expires_at) < ?', [$cutoff]);
    }
}
