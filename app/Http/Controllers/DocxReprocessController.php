<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ReprocessDocxArtifact;
use App\Application\PageCatalog\ReprocessDocxArtifactCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\DocxProcessingRejected;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PdfProcessingRejected;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Http\Support\DocxProcessingRejectionResponse;
use App\Http\Support\PdfProcessingRejectionResponse;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class DocxReprocessController
{
    use Concerns\ResolvesAuthenticatedUser;

    /** @throws ValidationException */
    public function __invoke(
        Request $request,
        Page $page,
        ReprocessDocxArtifact $reprocessDocxArtifact,
    ): RedirectResponse|Response {
        $expectedCurrentVersionUid = $request->input('current_version_uid');
        if (!is_string($expectedCurrentVersionUid) || $expectedCurrentVersionUid === '') {
            return response('This page changed since you opened it.', 409);
        }

        try {
            $reprocessDocxArtifact->handle(
                $this->authenticatedUser($request),
                new ReprocessDocxArtifactCommand(
                    pageUid: $page->uid,
                    expectedCurrentVersionUid: $expectedCurrentVersionUid,
                ),
            );
        } catch (BlockedPageContentException $exception) {
            throw ValidationException::withMessages(['docx' => $exception->getMessage()]);
        } catch (InvalidPageStatusTransition $exception) {
            throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
        } catch (StalePageVersionException $exception) {
            return response($exception->getMessage(), 409);
        } catch (DocxProcessingRejected $exception) {
            return DocxProcessingRejectionResponse::make($exception, $request, 'docx');
        } catch (PdfProcessingRejected $exception) {
            return PdfProcessingRejectionResponse::make($exception, $request, 'docx');
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages(['docx' => $exception->getMessage()]);
        }

        return redirect()
            ->route('pages.show', $page)
            ->with('status', 'Word document preview and search projection were refreshed.');
    }
}
