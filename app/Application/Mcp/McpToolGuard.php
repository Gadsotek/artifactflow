<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared guard for every MCP tool body: enforce the token scope the tool
 * requires and, for write tools, the per-token write rate limit before the
 * tool runs. Returns the early error envelope when a guard fails.
 */
final class McpToolGuard
{
    /**
     * @param callable(): McpToolResult $run
     */
    public function run(McpAccessToken $token, string $scope, bool $rateLimited, callable $run): McpToolResult
    {
        $scopeError = $this->requireScope($token, $scope);

        if ($scopeError instanceof McpToolResult) {
            return $scopeError;
        }

        if ($rateLimited) {
            $rateLimitError = $this->requireWriteRateLimit($token);

            if ($rateLimitError instanceof McpToolResult) {
                return $rateLimitError;
            }
        }

        return $run();
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
        $key = 'mcp-write:' . $token->uid;

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
