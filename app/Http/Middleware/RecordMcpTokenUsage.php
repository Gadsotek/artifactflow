<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpAccessToken;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecordMcpTokenUsage
{
    private const int WRITE_INTERVAL_SECONDS = 60;

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('mcp_access_token');

        if ($token instanceof McpAccessToken) {
            $this->recordUsage($token);
        }

        return $next($request);
    }

    private function recordUsage(McpAccessToken $token): void
    {
        $recordedAt = now();
        $cutoff = $recordedAt->copy()->subSeconds(self::WRITE_INTERVAL_SECONDS);

        if ($token->last_used_at?->greaterThan($cutoff) === true) {
            return;
        }

        $updated = McpAccessToken::query()
            ->whereKey($token->uid)
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $cutoff);
            })
            ->update(['last_used_at' => $recordedAt]);

        if ($updated === 1) {
            $token->forceFill(['last_used_at' => $recordedAt]);
        }
    }
}
