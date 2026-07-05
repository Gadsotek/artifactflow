<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\AddWorkspaceCollaborator;
use App\Application\Identity\AddWorkspaceCollaboratorCommand;
use App\Application\Identity\WorkspaceCollaboratorDirectory;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Http\Requests\Identity\StoreWorkspaceCollaboratorRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets a workspace inviter add registered human coworkers straight into a
 * shared workspace, without an email round-trip. The installation-wide human
 * directory is intentionally discoverable; the submitted UID remains inert
 * unless can:invite and the server-side membership rules both pass.
 */
final class WorkspaceCollaboratorController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function search(
        Request $request,
        Workspace $workspace,
        WorkspaceCollaboratorDirectory $directory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        $results = $directory->search(
            actor: $actor,
            targetWorkspaceUid: $workspace->uid,
            query: $request->string('q')->toString(),
        );

        return response()->json(['results' => $results]);
    }

    /**
     * @throws ValidationException
     */
    public function store(
        StoreWorkspaceCollaboratorRequest $request,
        Workspace $workspace,
        AddWorkspaceCollaborator $addWorkspaceCollaborator,
    ): RedirectResponse {
        try {
            $addWorkspaceCollaborator->handle(
                actor: $this->authenticatedUser($request),
                command: new AddWorkspaceCollaboratorCommand(
                    workspaceUid: $workspace->uid,
                    userUid: $request->string('user_uid')->toString(),
                    role: WorkspaceRole::from($request->string('role')->toString()),
                ),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'collaborator' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Collaborator added to the workspace.');
    }
}
