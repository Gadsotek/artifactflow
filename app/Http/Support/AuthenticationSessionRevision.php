<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;

final readonly class AuthenticationSessionRevision
{
    public const string SESSION_KEY = 'auth_revision';

    public function bind(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, $user->auth_revision);
    }

    public function isCurrent(Request $request, User $user): bool
    {
        $revision = $request->session()->get(self::SESSION_KEY);

        return is_int($revision) && $revision === $user->auth_revision;
    }
}
