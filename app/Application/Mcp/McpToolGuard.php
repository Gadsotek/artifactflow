<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared guard for every MCP tool body: enforce the token scope the tool
 * requires and, for write tools, the per-token write rate limit before the
 * tool runs. Returns the early error envelope when a guard fails.
 */
final readonly class McpToolGuard
{
    public function __construct(
        private McpJsonRpc $jsonRpc,
    ) {
    }

    /**
     * @param callable(): JsonResponse $run
     */
    public function run(mixed $id, McpAccessToken $token, string $scope, bool $rateLimited, callable $run): JsonResponse
    {
        $scopeError = $this->requireScope($id, $token, $scope);

        if ($scopeError instanceof JsonResponse) {
            return $scopeError;
        }

        if ($rateLimited) {
            $rateLimitError = $this->requireWriteRateLimit($id, $token);

            if ($rateLimitError instanceof JsonResponse) {
                return $rateLimitError;
            }
        }

        return $run();
    }

    private function requireScope(mixed $id, McpAccessToken $token, string $scope): ?JsonResponse
    {
        if ($token->hasScope($scope)) {
            return null;
        }

        return $this->jsonRpc->toolError($id, [
            'type' => 'insufficient_scope',
            'message' => sprintf('The %s scope is required.', $scope),
        ]);
    }

    private function requireWriteRateLimit(mixed $id, McpAccessToken $token): ?JsonResponse
    {
        $configuredLimit = config('rate_limits.mcp_writes_per_minute', 20);
        $limit = max(1, is_numeric($configuredLimit) ? (int) $configuredLimit : 20);
        $key = 'mcp-write:' . $token->uid;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'rate_limited',
                'message' => 'MCP write rate limit exceeded.',
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
