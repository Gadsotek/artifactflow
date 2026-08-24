<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PdfProcessingRejected;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Http\Requests\PageCatalog\StorePageVersionRequest;
use App\Http\Support\ImageNormalizationRejectionResponse;
use App\Http\Support\PdfProcessingRejectionResponse;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class PageVersionController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function store(
        StorePageVersionRequest $request,
        Page $page,
        UpdatePageContent $updatePageContent,
    ): RedirectResponse|Response {
        $user = $this->authenticatedUser($request);

        try {
            $updatePageContent->handle($user, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $request->pageContent(),
                source: $request->versionSource(),
                baseVersionUid: $request->baseVersionUid(),
                changeSummary: $request->changeSummary(),
            ));
        } catch (ImageNormalizationRejected $exception) {
            return ImageNormalizationRejectionResponse::make($exception);
        } catch (PdfProcessingRejected $exception) {
            return PdfProcessingRejectionResponse::make($exception, $request, 'pdf_file');
        } catch (BlockedPageContentException $exception) {
            throw ValidationException::withMessages([
                'content' => $exception->getMessage(),
            ]);
        } catch (InvalidPageStatusTransition $exception) {
            throw ValidationException::withMessages([
                'lifecycle' => $exception->getMessage(),
            ]);
        } catch (StalePageVersionException $exception) {
            return response($exception->getMessage(), 409);
        } catch (DomainRuleViolation $exception) {
            $field = match ($page->type) {
                PageType::Image => 'image_file',
                PageType::Pdf => 'pdf_file',
                default => 'content',
            };

            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }

        return redirect()->route('pages.show', $page);
    }

    /**
     * @throws ValidationException
     */
    public function restore(
        Request $request,
        Page $page,
        PageVersion $version,
        RestorePageVersion $restorePageVersion,
    ): RedirectResponse|Response {
        $user = $this->authenticatedUser($request);

        $expectedCurrentVersionUid = $request->input('current_version_uid');

        // The history dialog renders each Restore form with the version that was
        // current at render time. Enforcing it under the page lock turns a restore
        // launched from a stale dialog -- after someone else saved a newer version --
        // into a 409 conflict instead of silently overwriting that save. A missing
        // token means a stale or forged request that cannot be applied safely.
        if (!is_string($expectedCurrentVersionUid) || $expectedCurrentVersionUid === '') {
            return response('This page changed since you opened it.', 409);
        }

        try {
            $restorePageVersion->handle($user, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $version->uid,
                expectedCurrentVersionUid: $expectedCurrentVersionUid,
            ));
        } catch (PdfProcessingRejected $exception) {
            return PdfProcessingRejectionResponse::make($exception, $request, 'version_uid');
        } catch (BlockedPageContentException $exception) {
            throw ValidationException::withMessages([
                'content' => $exception->getMessage(),
            ]);
        } catch (InvalidPageStatusTransition $exception) {
            throw ValidationException::withMessages([
                'lifecycle' => $exception->getMessage(),
            ]);
        } catch (StalePageVersionException $exception) {
            return response($exception->getMessage(), 409);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'version_uid' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('pages.show', $page);
    }
}
