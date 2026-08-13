<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\Identity\ReparentWorkspaceImpactPreview;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\PreviewReparentWorkspaceRequest;
use App\Http\Requests\Identity\ReparentWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkspaceHierarchyController
{
    use Concerns\ResolvesAuthenticatedUser;

    private const string CONFIRMATION_SESSION_KEY = 'workspace_hierarchy_confirmation';

    public function preview(
        PreviewReparentWorkspaceRequest $request,
        Workspace $workspace,
        ReparentWorkspace $reparentWorkspace,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        try {
            $impact = $reparentWorkspace->preview($user, new ReparentWorkspaceCommand(
                workspaceUid: $workspace->uid,
                newParentWorkspaceUid: $request->parentWorkspaceUid(),
            ));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'parent_workspace_uid' => $exception->getMessage(),
            ]);
        }

        $request->session()->put(self::CONFIRMATION_SESSION_KEY, [
            'preview_id' => (string) Str::ulid(),
            'workspace_uid' => $impact->workspaceUid,
            'new_parent_workspace_uid' => $impact->newParentWorkspaceUid,
            'moved_workspace_count' => $impact->movedWorkspaceCount,
            'affected_page_count' => $impact->affectedPageCount,
            'gained_user_count' => $impact->gainedUserCount,
            'reduced_user_count' => $impact->reducedUserCount,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()
            ->route('dashboard', ['tab' => 'settings'])
            ->with('status', 'Review the hierarchy impact before confirming.');
    }

    public function update(
        ReparentWorkspaceRequest $request,
        Workspace $workspace,
        ReparentWorkspace $reparentWorkspace,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $expectedImpact = $this->expectedImpact($request, $workspace);

        try {
            $updated = $reparentWorkspace->handle($user, new ReparentWorkspaceCommand(
                workspaceUid: $workspace->uid,
                newParentWorkspaceUid: $request->parentWorkspaceUid(),
                confirmed: true,
                expectedImpact: $expectedImpact,
            ));
        } catch (DomainRuleViolation $exception) {
            $request->session()->forget(self::CONFIRMATION_SESSION_KEY);

            throw ValidationException::withMessages([
                'parent_workspace_uid' => $exception->getMessage(),
            ]);
        }

        $request->session()->forget(self::CONFIRMATION_SESSION_KEY);
        $request->session()->put('current_workspace_uid', $updated->uid);

        return redirect()
            ->route('dashboard', ['tab' => 'settings'])
            ->with('status', 'Workspace hierarchy updated.');
    }

    private function expectedImpact(
        ReparentWorkspaceRequest $request,
        Workspace $workspace,
    ): ReparentWorkspaceImpactPreview {
        $confirmation = $request->session()->get(self::CONFIRMATION_SESSION_KEY);

        if (
            !is_array($confirmation)
            || ($confirmation['preview_id'] ?? null) !== $request->previewId()
            || ($confirmation['workspace_uid'] ?? null) !== $workspace->uid
            || ($confirmation['new_parent_workspace_uid'] ?? null) !== $request->parentWorkspaceUid()
            || !is_int($confirmation['expires_at'] ?? null)
            || $confirmation['expires_at'] < now()->getTimestamp()
        ) {
            throw ValidationException::withMessages([
                'confirmed' => 'Review the current hierarchy impact before confirming this change.',
            ]);
        }

        $movedWorkspaceCount = $confirmation['moved_workspace_count'] ?? null;
        $affectedPageCount = $confirmation['affected_page_count'] ?? null;
        $gainedUserCount = $confirmation['gained_user_count'] ?? null;
        $reducedUserCount = $confirmation['reduced_user_count'] ?? null;

        if (
            !is_int($movedWorkspaceCount)
            || !is_int($affectedPageCount)
            || !is_int($gainedUserCount)
            || !is_int($reducedUserCount)
        ) {
            throw ValidationException::withMessages([
                'confirmed' => 'Review the current hierarchy impact before confirming this change.',
            ]);
        }

        return new ReparentWorkspaceImpactPreview(
            workspaceUid: $workspace->uid,
            newParentWorkspaceUid: $request->parentWorkspaceUid(),
            movedWorkspaceCount: $movedWorkspaceCount,
            affectedPageCount: $affectedPageCount,
            gainedUserCount: $gainedUserCount,
            reducedUserCount: $reducedUserCount,
        );
    }
}
