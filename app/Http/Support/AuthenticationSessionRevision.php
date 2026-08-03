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
        $this->bindRevision($request, $user->auth_revision);
    }

    public function bindRevision(Request $request, int $authRevision): void
    {
        $request->session()->put(self::SESSION_KEY, $authRevision);
    }

    public function isCurrent(Request $request, User $user): bool
    {
        $revision = $request->session()->get(self::SESSION_KEY);

        return is_int($revision) && $revision === $user->auth_revision;
    }
}
