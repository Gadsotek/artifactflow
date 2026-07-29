<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\TurnstileConfiguration;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireValidTurnstileConfiguration
{
    public function __construct(
        private TurnstileConfiguration $turnstile,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->turnstile->hasPartialCredentials()) {
            return $this->configurationError();
        }

        if ($this->turnstile->hasCompleteCredentials()) {
            try {
                $this->turnstile->expectedHostname();
                $this->turnstile->connectTimeoutSeconds();
                $this->turnstile->requestTimeoutSeconds();
            } catch (RuntimeException) {
                return $this->configurationError();
            }
        }

        return $next($request);
    }

    private function configurationError(): Response
    {
        return response()->view('auth.turnstile-configuration-error', status: 503);
    }
}
