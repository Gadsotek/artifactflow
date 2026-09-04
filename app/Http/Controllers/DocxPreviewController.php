<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\DocxPreviewContentReader;
use App\Application\PageCatalog\DocxPreviewUrl;
use App\Domain\PageCatalog\DocumentOriginalPurpose;
use App\Domain\PageCatalog\PageType;
use App\Http\Support\PdfArtifactResponder;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class DocxPreviewController
{
    public function __construct(
        private DocxPreviewContentReader $contentReader,
        private PdfArtifactResponder $responder,
    ) {
    }

    public function __invoke(
        Request $request,
        string $pageUid,
        string $versionUid,
        DocxPreviewUrl $url,
    ): Response {
        $page = Page::query()->find($pageUid);
        $version = PageVersion::query()->find($versionUid);
        if (!$page instanceof Page || !$version instanceof PageVersion) {
            $this->reject('missing_record', $pageUid, $versionUid);
        }
        $purpose = $url->validatedPurpose($page, $versionUid, $request);
        if (
            !$purpose instanceof DocumentOriginalPurpose
            || $page->type !== PageType::Docx
            || $version->page_uid !== $page->uid
            || ($purpose === DocumentOriginalPurpose::Current && $page->current_version_uid !== $version->uid)
        ) {
            $this->reject('invalid_claims', $pageUid, $versionUid);
        }
        $bytes = $this->contentReader->read($version);
        if ($bytes === null) {
            $this->reject('invalid_storage_content', $pageUid, $versionUid);
        }

        Log::info('docx_preview.served', [
            'page_uid' => $page->uid,
            'purpose' => $purpose->value,
            'version_uid' => $version->uid,
        ]);

        return $this->responder->inline($bytes, $page, $version);
    }

    private function reject(string $reason, string $pageUid, string $versionUid): never
    {
        Log::warning('docx_preview.rejected', [
            'page_uid' => $pageUid,
            'reason' => $reason,
            'version_uid' => $versionUid,
        ]);
        abort(404);
    }
}
