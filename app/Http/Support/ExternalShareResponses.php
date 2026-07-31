<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ExternalShareResponses
{
    /**
     * @param array<string, mixed> $payload JSON response boundary.
     */
    public function json(array $payload, int $status = 200): JsonResponse
    {
        return $this->secure(response()->json($payload, $status));
    }

    public function unavailableJson(): JsonResponse
    {
        return $this->json(['state' => 'unavailable'], 404);
    }

    public function unavailableView(): Response
    {
        return $this->secure(response()->view('external-shares.unavailable', status: 404));
    }

    /** @param array<mixed> $headers Framework throttle-callback boundary. */
    public function unavailableForRateLimitedRequest(
        Request $request,
        array $headers,
    ): SymfonyResponse {
        $response = $request->route()?->getName() === 'external-shares.viewer.content'
            ? $this->unavailableView()
            : $this->unavailableJson();

        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_int($value) && !is_string($value))) {
                continue;
            }

            $response->headers->set($name, (string) $value);
        }

        return $response;
    }

    /**
     * @template T of SymfonyResponse
     *
     * @param T $response
     *
     * @return T
     */
    public function secure(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
