<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\DeprecatePage;
use App\Application\PageCatalog\DeprecatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\MarkPageApproved;
use App\Application\PageCatalog\MarkPageApprovedCommand;
use App\Application\PageCatalog\RestoreDeprecatedPage;
use App\Application\PageCatalog\RestoreDeprecatedPageCommand;
use App\Application\PageCatalog\ReturnPageToDraft;
use App\Application\PageCatalog\ReturnPageToDraftCommand;
use App\Application\PageCatalog\UnarchivePage;
use App\Application\PageCatalog\UnarchivePageCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\PageCatalog\HardDeletePageRequest;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PageLifecycleController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function archive(Request $request, Page $page, ArchivePage $archivePage): RedirectResponse
    {
        return $this->transition($request, $page, 'confirmation', 'pages.show', function (User $actor) use ($archivePage, $request, $page): void {
            $archivePage->handle(
                actor: $actor,
                command: new ArchivePageCommand(
                    pageUid: $page->uid,
                    confirmed: $request->boolean('confirmed'),
                ),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function unarchive(Request $request, Page $page, UnarchivePage $unarchivePage): RedirectResponse
    {
        return $this->transition($request, $page, 'confirmation', 'pages.show', function (User $actor) use ($unarchivePage, $request, $page): void {
            $unarchivePage->handle(
                actor: $actor,
                command: new UnarchivePageCommand(
                    pageUid: $page->uid,
                    confirmed: $request->boolean('confirmed'),
                ),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function markApproved(
        Request $request,
        Page $page,
        MarkPageApproved $markPageApproved,
    ): RedirectResponse {
        return $this->transition($request, $page, 'lifecycle', 'pages.show', function (User $actor) use ($markPageApproved, $page): void {
            $markPageApproved->handle(
                actor: $actor,
                command: new MarkPageApprovedCommand($page->uid),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function returnToDraft(
        Request $request,
        Page $page,
        ReturnPageToDraft $returnPageToDraft,
    ): RedirectResponse {
        return $this->transition($request, $page, 'lifecycle', 'pages.show', function (User $actor) use ($returnPageToDraft, $page): void {
            $returnPageToDraft->handle(
                actor: $actor,
                command: new ReturnPageToDraftCommand($page->uid),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function deprecate(Request $request, Page $page, DeprecatePage $deprecatePage): RedirectResponse
    {
        return $this->transition($request, $page, 'lifecycle', 'pages.show', function (User $actor) use ($deprecatePage, $page): void {
            $deprecatePage->handle(
                actor: $actor,
                command: new DeprecatePageCommand($page->uid),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function restoreToDraft(
        Request $request,
        Page $page,
        RestoreDeprecatedPage $restoreDeprecatedPage,
    ): RedirectResponse {
        return $this->transition($request, $page, 'lifecycle', 'pages.show', function (User $actor) use ($restoreDeprecatedPage, $page): void {
            $restoreDeprecatedPage->handle(
                actor: $actor,
                command: new RestoreDeprecatedPageCommand($page->uid),
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function destroy(
        HardDeletePageRequest $request,
        Page $page,
        HardDeletePage $hardDeletePage,
    ): RedirectResponse {
        return $this->transition($request, $page, 'confirmation', 'pages.index', function (User $actor) use ($hardDeletePage, $request, $page): void {
            $hardDeletePage->handle(
                actor: $actor,
                command: new HardDeletePageCommand(
                    pageUid: $page->uid,
                    confirmation: $request->string('confirmation')->toString(),
                ),
            );
        });
    }

    /**
     * Every lifecycle action shares the same shape: resolve the actor, run one
     * handler, translate its domain-rule violation into a field validation error,
     * and redirect. Each handler raises only user-facing DomainRuleViolation
     * subtypes here, so mapping the message to the caller's field key is safe.
     *
     * @param callable(User): void $operation
     *
     * @throws ValidationException
     */
    private function transition(
        Request $request,
        Page $page,
        string $errorKey,
        string $redirectRoute,
        callable $operation,
    ): RedirectResponse {
        try {
            $operation($this->authenticatedUser($request));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                $errorKey => $exception->getMessage(),
            ]);
        }

        return $redirectRoute === 'pages.index'
            ? redirect()->route('pages.index')
            : redirect()->route($redirectRoute, $page);
    }
}
