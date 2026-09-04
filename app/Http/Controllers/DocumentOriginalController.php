<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\DocumentOriginalUrl;
use App\Domain\PageCatalog\DocumentOriginalPurpose;
use App\Domain\PageCatalog\PageType;
use App\Http\Support\DocumentOriginalResponder;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class DocumentOriginalController
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private DocumentOriginalResponder $responder,
    ) {
    }

    public function __invoke(
        Request $request,
        string $pageUid,
        string $versionUid,
        DocumentOriginalUrl $url,
    ): Response {
        $page = Page::query()->find($pageUid);
        $version = PageVersion::query()->find($versionUid);

        if (!$page instanceof Page || !$version instanceof PageVersion) {
            $this->reject('missing_record', $pageUid, $versionUid);
        }

        $purpose = $url->validatedPurpose($page, $versionUid, $request);

        if (
            !$purpose instanceof DocumentOriginalPurpose
            || !in_array($page->type, [PageType::Xlsx, PageType::Docx], true)
            || $version->page_uid !== $page->uid
            || ($purpose === DocumentOriginalPurpose::Current && $page->current_version_uid !== $version->uid)
        ) {
            $this->reject('invalid_claims', $pageUid, $versionUid);
        }

        $bytes = $this->contentReader->read($version->content_storage_path, $version->byte_size);

        if (
            $bytes === null
            || strlen($bytes) !== $version->byte_size
            || !hash_equals($version->content_hash, hash('sha256', $bytes))
        ) {
            $this->reject('invalid_storage_content', $pageUid, $versionUid);
        }

        Log::info('document_original.served', [
            'page_uid' => $page->uid,
            'purpose' => $purpose->value,
            'version_uid' => $version->uid,
        ]);

        return $this->responder->attachment($bytes, $page, $version);
    }

    private function reject(string $reason, string $pageUid, string $versionUid): never
    {
        Log::warning('document_original.rejected', [
            'page_uid' => $pageUid,
            'reason' => $reason,
            'version_uid' => $versionUid,
        ]);

        abort(404);
    }
}
