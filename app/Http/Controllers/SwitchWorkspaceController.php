<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchWorkspaceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __invoke(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        $isMember = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $user->uid)
            ->exists();

        if (!$isMember) {
            abort(403);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()->route('dashboard');
    }
}
