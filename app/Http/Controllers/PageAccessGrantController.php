<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\WorkspaceCollaboratorDirectory;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageAccessGrantTargetResolver;
use App\Application\PageCatalog\PageAccessGrantTargetUnavailable;
use App\Application\PageCatalog\RevokePageAccess;
use App\Application\PageCatalog\RevokePageAccessCommand;
use App\Application\PageCatalog\UpdatePageAccessMode;
use App\Application\PageCatalog\UpdatePageAccessModeCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\PageCatalog\StorePageAccessGrantRequest;
use App\Http\Requests\PageCatalog\UpdatePageAccessModeRequest;
use App\Models\Page;
use App\Models\PageAccessGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PageAccessGrantController
{
    use Concerns\ResolvesAuthenticatedUser;

    // Shown for both a real grant and an unavailable target. The internal human
    // directory is intentionally discoverable, but external/unknown addresses
    // still receive a neutral response rather than account-state disclosure.
    private const string ACCESS_GRANT_STATUS = 'If that email belongs to an eligible registered coworker, their access has been granted.';

    private const string ALTERNATE_ACCESS_STATUS = 'Access grant revoked. You still have access through another role.';

    private const string OWN_ACCESS_REMOVED_STATUS = 'Your page access was removed.';

    public function searchUsers(
        Request $request,
        Page $page,
        WorkspaceCollaboratorDirectory $directory,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        return response()->json([
            'results' => $directory->searchKnownUsers(
                actor: $actor,
                query: $request->string('q')->toString(),
            ),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(
        StorePageAccessGrantRequest $request,
        Page $page,
        GrantPageAccess $grantPageAccess,
        PageAccessGrantTargetResolver $targetResolver,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        try {
            $subjectType = $request->subjectType();
            $grantPageAccess->handle($user, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: $subjectType,
                subjectUid: $targetResolver->resolve(
                    subjectType: $subjectType,
                    userEmail: $request->userEmail(),
                    workspaceUid: $request->workspaceUid(),
                ),
                role: $request->workspaceRole(),
            ));
        } catch (PageAccessGrantTargetUnavailable) {
            return redirect()
                ->route('pages.show', $page)
                ->with('status', self::ACCESS_GRANT_STATUS);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'access_target' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('pages.show', $page)
            ->with('status', self::ACCESS_GRANT_STATUS);
    }

    public function updateMode(
        UpdatePageAccessModeRequest $request,
        Page $page,
        UpdatePageAccessMode $updatePageAccessMode,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        $updatePageAccessMode->handle($user, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: $request->accessMode(),
        ));

        return redirect()->route('pages.show', $page);
    }

    public function destroy(
        Request $request,
        Page $page,
        PageAccessGrant $grant,
        RevokePageAccess $revokePageAccess,
        PageAccess $access,
    ): RedirectResponse {
        if ($grant->page_uid !== $page->uid) {
            abort(404);
        }

        $user = $this->authenticatedUser($request);

        $revoked = $revokePageAccess->handle($user, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: $grant->subject_type,
            subjectUid: $grant->subject_uid,
        ));

        if (!$revoked) {
            return redirect()->route('pages.show', $page);
        }

        if ($access->canView($user, $page->refresh())) {
            return redirect()
                ->route('pages.show', $page)
                ->with('status', self::ALTERNATE_ACCESS_STATUS);
        }

        return redirect()
            ->route('pages.index', ['workspace_uid' => 'all'])
            ->with('status', self::OWN_ACCESS_REMOVED_STATUS);
    }
}
