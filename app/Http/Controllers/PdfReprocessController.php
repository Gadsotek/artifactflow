<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ReprocessPdfArtifact;
use App\Application\PageCatalog\ReprocessPdfArtifactCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PdfProcessingRejected;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Http\Support\PdfProcessingRejectionResponse;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class PdfReprocessController
{
    use Concerns\ResolvesAuthenticatedUser;

    /** @throws ValidationException */
    public function __invoke(
        Request $request,
        Page $page,
        ReprocessPdfArtifact $reprocessPdfArtifact,
    ): RedirectResponse|Response {
        $expectedCurrentVersionUid = $request->input('current_version_uid');

        if (!is_string($expectedCurrentVersionUid) || $expectedCurrentVersionUid === '') {
            return response('This page changed since you opened it.', 409);
        }

        try {
            $reprocessPdfArtifact->handle(
                $this->authenticatedUser($request),
                new ReprocessPdfArtifactCommand(
                    pageUid: $page->uid,
                    expectedCurrentVersionUid: $expectedCurrentVersionUid,
                ),
            );
        } catch (BlockedPageContentException $exception) {
            throw ValidationException::withMessages([
                'pdf' => $exception->getMessage(),
            ]);
        } catch (InvalidPageStatusTransition $exception) {
            throw ValidationException::withMessages([
                'lifecycle' => $exception->getMessage(),
            ]);
        } catch (StalePageVersionException $exception) {
            return response($exception->getMessage(), 409);
        } catch (PdfProcessingRejected $exception) {
            return PdfProcessingRejectionResponse::make($exception);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'pdf' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('pages.show', $page)
            ->with('status', 'PDF text and processing facts were refreshed.');
    }
}
