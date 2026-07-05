<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\RemoveWorkspaceMember;
use App\Application\Identity\RemoveWorkspaceMemberCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\RemoveWorkspaceMemberRequest;
use App\Http\Requests\Identity\UpdateWorkspaceMembershipRoleRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class WorkspaceMembershipController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function update(
        UpdateWorkspaceMembershipRoleRequest $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
        ChangeWorkspaceMembershipRole $changeRole,
    ): RedirectResponse {
        if ($membership->workspace_uid !== $workspace->uid) {
            abort(404);
        }

        $user = $this->authenticatedUser($request);

        try {
            $changeRole->handle($user, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                role: $request->workspaceRole(),
            ));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'role' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace member role updated.');
    }

    /**
     * @throws ValidationException
     */
    public function destroy(
        RemoveWorkspaceMemberRequest $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
        RemoveWorkspaceMember $removeMember,
    ): RedirectResponse {
        if ($membership->workspace_uid !== $workspace->uid) {
            abort(404);
        }

        $user = $this->authenticatedUser($request);

        try {
            $removeMember->handle($user, new RemoveWorkspaceMemberCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                replacementOwnerUserUid: $request->replacementOwnerUserUid(),
            ));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'replacement_owner_user_uid' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace member removed.');
    }
}
