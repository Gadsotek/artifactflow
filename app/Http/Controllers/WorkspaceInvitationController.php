<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\AcceptWorkspaceInvitation;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\RevokeWorkspaceInvitation;
use App\Application\Identity\RevokeWorkspaceInvitationCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Http\Requests\Identity\StoreWorkspaceInvitationRequest;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class WorkspaceInvitationController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function store(
        StoreWorkspaceInvitationRequest $request,
        Workspace $workspace,
        InviteUserToWorkspace $inviteUserToWorkspace,
    ): RedirectResponse {
        try {
            $inviteUserToWorkspace->handle(
                actor: $this->authenticatedUser($request),
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: $request->string('email')->toString(),
                    role: WorkspaceRole::from($request->string('role')->toString()),
                ),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'workspace' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        if ($request->string('return_to')->toString() === 'library') {
            return redirect()
                ->route('pages.index', ['workspace_uid' => $workspace->uid])
                ->with('status', 'Workspace invitation sent.');
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace invitation sent.');
    }

    /**
     * @throws ValidationException
     */
    public function accept(
        Request $request,
        WorkspaceInvitation $invitation,
        AcceptWorkspaceInvitation $acceptWorkspaceInvitation,
    ): RedirectResponse {
        try {
            Gate::authorize('accept', $invitation);
            $membership = $acceptWorkspaceInvitation->handle(
                $this->authenticatedUser($request),
                $invitation,
            );
        } catch (AuthorizationException|DomainRuleViolation) {
            return redirect()
                ->route('dashboard')
                ->withErrors(['invitation' => 'Workspace invitation cannot be accepted.']);
        }

        $request->session()->put('current_workspace_uid', $membership->workspace_uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace invitation accepted.');
    }

    /**
     * Backwards-compatible landing point for invitation emails delivered before
     * the accept action moved behind a POST confirmation page. Those messages
     * link to GET /accept; redirect them to the confirmation page rather than
     * returning a 405. This handler never mutates state — acceptance still runs
     * only through the throttled, CSRF-protected POST.
     */
    public function acceptLink(WorkspaceInvitation $invitation): RedirectResponse
    {
        return redirect()->route('workspace-invitations.show', $invitation);
    }

    public function show(WorkspaceInvitation $invitation): View|RedirectResponse
    {
        if (Gate::denies('accept', $invitation)) {
            return redirect()
                ->route('dashboard')
                ->withErrors(['invitation' => 'Workspace invitation cannot be accepted.']);
        }

        $workspace = Workspace::query()->findOrFail($invitation->workspace_uid);

        return view('invitations.show', [
            'invitation' => $invitation,
            'workspaceName' => $workspace->name,
            'roleLabel' => ucfirst($invitation->role->value),
            'expiresAt' => $invitation->expires_at,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        RevokeWorkspaceInvitation $revokeWorkspaceInvitation,
    ): RedirectResponse {
        if ($invitation->workspace_uid !== $workspace->uid) {
            abort(404);
        }

        try {
            $revokeWorkspaceInvitation->handle(
                actor: $this->authenticatedUser($request),
                command: new RevokeWorkspaceInvitationCommand(
                    workspaceUid: $workspace->uid,
                    invitationUid: $invitation->uid,
                ),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'invitation' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace invitation revoked.');
    }
}
