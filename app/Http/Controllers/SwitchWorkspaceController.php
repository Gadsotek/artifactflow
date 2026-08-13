<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\WorkspaceAccess;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchWorkspaceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {
    }

    public function __invoke(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        $isMember = $this->workspaceAccess->role($user->uid, $workspace->uid) !== null;

        if (!$isMember) {
            abort(403);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()->route('dashboard');
    }
}
