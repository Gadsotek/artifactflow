<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Stateless framework glue only: resolves the authenticated user at the HTTP
 * boundary or aborts. Authorization decisions stay in PageAccess and policies.
 */
trait ResolvesAuthenticatedUser
{
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
