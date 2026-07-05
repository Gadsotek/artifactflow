<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\PageCatalog\MovePageWorkspaceRequest;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class PageWorkspaceController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function update(
        MovePageWorkspaceRequest $request,
        Page $page,
        MovePageToWorkspace $movePageToWorkspace,
    ): RedirectResponse {
        try {
            $movePageToWorkspace->handle(
                actor: $this->authenticatedUser($request),
                command: new MovePageToWorkspaceCommand(
                    pageUid: $page->uid,
                    targetWorkspaceUid: $request->targetWorkspaceUid(),
                    targetOwnerUserUid: $request->targetOwnerUserUid(),
                    confirmed: $request->boolean('confirm_move'),
                ),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'workspace' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('pages.show', $page)
            ->with('status', 'Page moved to the selected workspace.');
    }
}
