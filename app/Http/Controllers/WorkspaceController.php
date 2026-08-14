<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\StoreWorkspaceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class WorkspaceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function store(StoreWorkspaceRequest $request, CreateSharedWorkspace $createSharedWorkspace): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        try {
            $workspace = $createSharedWorkspace->handle(
                $user,
                $request->workspaceName(),
                $request->parentWorkspaceUid(),
                $request->inheritsParentMemberships(),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                $request->parentWorkspaceUid() === null ? 'name' : 'parent_workspace_uid' => $exception->getMessage(),
            ]);
        }
        $request->session()->put('current_workspace_uid', $workspace->uid);

        if ($request->returnsToLibrary()) {
            return redirect()->route('pages.index', ['workspace_uid' => $workspace->uid]);
        }

        return redirect()->route('dashboard');
    }
}
