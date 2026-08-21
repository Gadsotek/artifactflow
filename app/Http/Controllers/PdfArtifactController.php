<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\PdfArtifactContentReader;
use App\Application\PageCatalog\PdfArtifactUrl;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PdfArtifactPurpose;
use App\Http\Support\PdfArtifactResponder;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class PdfArtifactController
{
    public function __construct(
        private PdfArtifactContentReader $contentReader,
        private PdfArtifactResponder $responder,
    ) {
    }

    public function __invoke(
        Request $request,
        string $pageUid,
        string $versionUid,
        PdfArtifactUrl $artifactUrl,
        PdfProcessorConfiguration $processorConfiguration,
    ): Response {
        if (!$processorConfiguration->enabled()) {
            $this->rejectNotFound('disabled', $pageUid, $versionUid);
        }

        $page = Page::query()->find($pageUid);
        $version = PageVersion::query()->find($versionUid);

        if (!$page instanceof Page || !$version instanceof PageVersion) {
            $this->rejectNotFound('missing_record', $pageUid, $versionUid);
        }

        $purpose = $artifactUrl->validatedPurpose($page, $versionUid, $request);

        if (!$purpose instanceof PdfArtifactPurpose) {
            $this->rejectNotFound('invalid_claims', $pageUid, $versionUid);
        }

        if (
            $page->type !== PageType::Pdf
            || $version->page_uid !== $page->uid
            || ($purpose === PdfArtifactPurpose::Current && $page->current_version_uid !== $version->uid)
        ) {
            $this->rejectNotFound('invalid_version', $pageUid, $versionUid);
        }

        $content = $this->contentReader->read($version);

        if ($content === null) {
            $this->rejectNotFound('invalid_storage_content', $pageUid, $versionUid);
        }

        Log::info('pdf_artifact.served', [
            'page_uid' => $page->uid,
            'purpose' => $purpose->value,
            'version_uid' => $version->uid,
        ]);

        return $this->responder->original($content, $page, $version, $purpose);
    }

    private function rejectNotFound(string $reason, string $pageUid, string $versionUid): never
    {
        Log::warning('pdf_artifact.rejected', [
            'page_uid' => $pageUid,
            'reason' => $reason,
            'version_uid' => $versionUid,
        ]);

        abort(404);
    }
}
