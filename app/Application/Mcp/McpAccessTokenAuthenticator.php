<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\McpAccessToken;
use Illuminate\Http\Request;

final class McpAccessTokenAuthenticator
{
    public function authenticate(Request $request): ?McpAccessToken
    {
        $plainTextToken = $this->bearerToken($request);

        if ($plainTextToken === null) {
            return null;
        }

        $token = McpAccessToken::query()
            ->with('principal')
            ->where('token_hash', McpAccessTokenIssuer::hashToken($plainTextToken))
            ->first();

        if (!$token instanceof McpAccessToken) {
            return null;
        }

        if ($token->revoked_at !== null || $token->isExpired()) {
            return null;
        }

        if (!McpAccessTokenIssuer::principalCanUseMcp($token->principal)) {
            return null;
        }

        return $token;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
