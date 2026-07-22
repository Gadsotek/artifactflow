<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\InvitationNoLongerPending;
use App\Application\Identity\RecordSuccessfulLogin;
use App\Application\Identity\RegisterWorkspaceInvitationUser;
use App\Application\Identity\RegisterWorkspaceInvitationUserCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\RegisterWorkspaceInvitationUserRequest;
use App\Http\Support\AuthenticationSessionRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Public landing for the token in an invitation email. It lets an invited person
 * who has no account finish registration and join — without opening general
 * registration: reaching this flow requires a valid, pending invitation token,
 * and any account created here is bound to the invited email (never a
 * user-supplied one). It also routes people who already have an account to
 * sign-in, and matching signed-in users to the accept confirmation page.
 */
final class WorkspaceInvitationJoinController
{
    public function __construct(
        private readonly RegisterWorkspaceInvitationUser $registerInvitationUser,
        private readonly RecordSuccessfulLogin $recordSuccessfulLogin,
        private readonly AuthenticationSessionRevision $sessionRevision,
    ) {
    }

    public function show(Request $request, WorkspaceInvitation $invitation): View|RedirectResponse
    {
        if (!$invitation->isPending()) {
            return $this->invalidInvitationRedirect();
        }

        $invitedEmail = $invitation->invited_email;
        $currentUser = $request->user();

        if ($currentUser instanceof User) {
            // Already signed in as the invited person: hand off to the confirm page.
            if ($this->normalizeEmail($currentUser->email) === $invitedEmail) {
                return redirect()->route('workspace-invitations.show', $invitation);
            }

            return $this->joinView($invitation, 'wrong_account');
        }

        // Guests with an existing account sign in (and are returned to accept);
        // guests without one finish registration here.
        if (User::query()->where('email', $invitedEmail)->exists()) {
            $request->session()->put('url.intended', route('workspace-invitations.show', $invitation, false));

            return $this->joinView($invitation, 'sign_in');
        }

        return $this->joinView($invitation, 'register');
    }

    public function register(
        RegisterWorkspaceInvitationUserRequest $request,
        WorkspaceInvitation $invitation,
    ): RedirectResponse {
        if (!$invitation->isPending()) {
            return $this->invalidInvitationRedirect();
        }

        if ($request->user() instanceof User) {
            return redirect()->route('workspace-invitations.join', ['invitation' => $invitation->plainToken]);
        }

        $invitedEmail = $invitation->invited_email;

        // Registration only ever creates the invited account. If one already
        // exists (or appears mid-flow), the person signs in instead.
        if (User::query()->where('email', $invitedEmail)->exists()) {
            return redirect()
                ->route('workspace-invitations.join', ['invitation' => $invitation->plainToken])
                ->withErrors(['name' => 'An account already exists for this invitation. Please sign in to join.']);
        }

        try {
            $registration = $this->registerInvitationUser->handle(new RegisterWorkspaceInvitationUserCommand(
                invitationUid: $invitation->uid,
                presentedToken: (string) $invitation->plainToken,
                name: $request->name(),
                password: $request->password(),
            ));
        } catch (InvitationNoLongerPending) {
            // The invitation was revoked or expired during registration; the account
            // created in the same transaction has been rolled back with it.
            return $this->invalidInvitationRedirect();
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        } catch (AuthorizationException) {
            return $this->invalidInvitationRedirect();
        }

        // Session and login side effects run only after the account+membership commit.
        Auth::login($registration->user);
        $request->session()->regenerate();
        $this->sessionRevision->bind($request, $registration->user);
        $this->recordSuccessfulLogin->handle($registration->user);
        $request->session()->put('current_workspace_uid', $registration->membership->workspace_uid);

        return redirect()
            ->route('dashboard')
            ->with('status', sprintf('Welcome! You have joined the %s workspace.', $this->workspaceName($invitation)));
    }

    private function joinView(WorkspaceInvitation $invitation, string $mode): View
    {
        return view('invitations.join', [
            'invitation' => $invitation,
            'mode' => $mode,
            'invitedEmail' => $invitation->invited_email,
            'workspaceName' => $this->workspaceName($invitation),
            'roleLabel' => ucfirst($invitation->role->value),
        ]);
    }

    private function invalidInvitationRedirect(): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['invitation' => 'This workspace invitation is no longer valid.']);
    }

    private function workspaceName(WorkspaceInvitation $invitation): string
    {
        $workspace = Workspace::query()->find($invitation->workspace_uid);

        return $workspace instanceof Workspace ? $workspace->name : 'workspace';
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
