<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ReprocessXlsxArtifact;
use App\Application\PageCatalog\ReprocessXlsxArtifactCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Domain\PageCatalog\XlsxProcessingRejected;
use App\Http\Support\XlsxProcessingRejectionResponse;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class XlsxReprocessController
{
    use Concerns\ResolvesAuthenticatedUser;

    /** @throws ValidationException */
    public function __invoke(
        Request $request,
        Page $page,
        ReprocessXlsxArtifact $reprocessXlsxArtifact,
    ): RedirectResponse|Response {
        $expectedCurrentVersionUid = $request->input('current_version_uid');

        if (!is_string($expectedCurrentVersionUid) || $expectedCurrentVersionUid === '') {
            return response('This page changed since you opened it.', 409);
        }

        try {
            $reprocessXlsxArtifact->handle(
                $this->authenticatedUser($request),
                new ReprocessXlsxArtifactCommand(
                    pageUid: $page->uid,
                    expectedCurrentVersionUid: $expectedCurrentVersionUid,
                ),
            );
        } catch (BlockedPageContentException $exception) {
            throw ValidationException::withMessages(['xlsx' => $exception->getMessage()]);
        } catch (InvalidPageStatusTransition $exception) {
            throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
        } catch (StalePageVersionException $exception) {
            return response($exception->getMessage(), 409);
        } catch (XlsxProcessingRejected $exception) {
            return XlsxProcessingRejectionResponse::make($exception, $request, 'xlsx');
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages(['xlsx' => $exception->getMessage()]);
        }

        return redirect()
            ->route('pages.show', $page)
            ->with('status', 'Excel workbook preview and search projection were refreshed.');
    }
}
