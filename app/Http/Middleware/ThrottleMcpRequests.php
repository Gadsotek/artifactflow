<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleMcpRequests
{
    private const int DECAY_SECONDS = 60;

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts = $this->configuredLimit();
        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }

    private function configuredLimit(): int
    {
        $value = config('rate_limits.mcp_pre_auth_per_minute', 300);
        $limit = is_int($value) || is_string($value) ? (int) $value : 300;

        return max(1, $limit);
    }

    private function throttleKey(Request $request): string
    {
        return 'mcp-ip:' . ($request->ip() ?? 'unknown');
    }
}
