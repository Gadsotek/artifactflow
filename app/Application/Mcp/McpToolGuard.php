<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared guard for every MCP tool body: enforce the token scope the tool
 * requires and, for write tools, the per-principal write rate limit before the
 * tool runs. Returns the early error envelope when a guard fails.
 */
final readonly class McpToolGuard
{
    public function __construct(private McpAccessTokenExecutionLock $executionLock)
    {
    }

    /**
     * @param string|list<string> $scopes
     * @param Closure(McpAccessToken): McpToolResult $run
     */
    public function run(McpAccessToken $token, string|array $scopes, bool $rateLimited, Closure $run): McpToolResult
    {
        return $this->executionLock->runShared($token->uid, function () use (
            $rateLimited,
            $run,
            $scopes,
            $token,
        ): McpToolResult {
            $liveToken = McpAccessToken::query()
                ->with('principal')
                ->whereKey($token->uid)
                ->first();

            if (
                !$liveToken instanceof McpAccessToken
                || $liveToken->revoked_at !== null
                || $liveToken->isExpired()
                || !McpAccessTokenIssuer::principalCanUseMcp($liveToken->principal)
            ) {
                return McpToolResult::error([
                    'type' => 'authentication_required',
                    'message' => 'The MCP access token is no longer active.',
                ]);
            }

            foreach ((array) $scopes as $scope) {
                $scopeError = $this->requireScope($liveToken, $scope);

                if ($scopeError instanceof McpToolResult) {
                    return $scopeError;
                }
            }

            if ($rateLimited) {
                $rateLimitError = $this->requireWriteRateLimit($liveToken);

                if ($rateLimitError instanceof McpToolResult) {
                    return $rateLimitError;
                }
            }

            return $run($liveToken);
        });
    }

    private function requireScope(McpAccessToken $token, string $scope): ?McpToolResult
    {
        if ($token->hasScope($scope)) {
            return null;
        }

        return McpToolResult::error([
            'type' => 'insufficient_scope',
            'message' => sprintf('The %s scope is required.', $scope),
        ]);
    }

    private function requireWriteRateLimit(McpAccessToken $token): ?McpToolResult
    {
        $configuredLimit = config('rate_limits.mcp_writes_per_minute', 20);
        $limit = max(1, is_numeric($configuredLimit) ? (int) $configuredLimit : 20);
        $key = 'mcp-write-principal:' . $token->principal_user_uid;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return McpToolResult::error([
                'type' => 'rate_limited',
                'message' => 'MCP write rate limit exceeded.',
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
