<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\StalePageMetadataException;
use App\Http\Requests\PageCatalog\UpdatePageMetadataRequest;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class PageMetadataController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function update(
        UpdatePageMetadataRequest $request,
        Page $page,
        UpdatePageMetadata $updatePageMetadata,
    ): RedirectResponse|Response {
        try {
            $updatePageMetadata->handle(
                actor: $this->authenticatedUser($request),
                command: new UpdatePageMetadataCommand(
                    pageUid: $page->uid,
                    expectedMetadataRevision: $request->expectedMetadataRevision(),
                    title: $request->string('title')->toString(),
                    description: $request->nullableString('description'),
                    categoryUid: $request->nullableString('category_uid'),
                    parentPageUid: $request->nullableString('parent_page_uid'),
                    ownerUserUid: $request->string('owner_user_uid')->toString(),
                    tagNames: $request->tagNames(),
                ),
            );
        } catch (StalePageMetadataException $exception) {
            return response($exception->getMessage(), 409);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'metadata' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('pages.show', $page);
    }
}
