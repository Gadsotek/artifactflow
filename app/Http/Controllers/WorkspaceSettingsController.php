<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\UpdateWorkspaceSettings;
use App\Application\Identity\UpdateWorkspaceSettingsCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\UpdateWorkspaceSettingsRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class WorkspaceSettingsController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function __invoke(
        UpdateWorkspaceSettingsRequest $request,
        Workspace $workspace,
        UpdateWorkspaceSettings $updateWorkspaceSettings,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        try {
            $updateWorkspaceSettings->handle($user, new UpdateWorkspaceSettingsCommand(
                workspaceUid: $workspace->uid,
                name: $request->string('name')->toString(),
                allowEditorInvites: $request->has('allow_editor_invites')
                    ? $request->boolean('allow_editor_invites')
                    : $workspace->allow_editor_invites,
                allowEditorPageSharing: $request->has('allow_editor_page_sharing')
                    ? $request->boolean('allow_editor_page_sharing')
                    : $workspace->allow_editor_page_sharing,
            ));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'settings' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Workspace settings updated.');
    }
}
