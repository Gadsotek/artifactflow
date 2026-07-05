<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\CreateSharedWorkspace;
use App\Http\Requests\Identity\StoreWorkspaceRequest;
use Illuminate\Http\RedirectResponse;

final class WorkspaceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function store(StoreWorkspaceRequest $request, CreateSharedWorkspace $createSharedWorkspace): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        $workspace = $createSharedWorkspace->handle($user, $request->workspaceName());
        $request->session()->put('current_workspace_uid', $workspace->uid);

        if ($request->returnsToLibrary()) {
            return redirect()->route('pages.index', ['workspace_uid' => $workspace->uid]);
        }

        return redirect()->route('dashboard');
    }
}
