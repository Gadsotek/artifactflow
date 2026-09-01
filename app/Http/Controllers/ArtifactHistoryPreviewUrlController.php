<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\DocxPreviewUrl;
use App\Application\PageCatalog\DocxProcessorConfiguration;
use App\Application\PageCatalog\PdfArtifactUrl;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Application\PageCatalog\XlsxProcessorConfiguration;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ArtifactHistoryPreviewUrlController
{
    public function __invoke(
        Page $page,
        PageVersion $version,
        ArtifactPreviewUrl $previewUrl,
        PdfArtifactUrl $pdfArtifactUrl,
        DocxPreviewUrl $docxPreviewUrl,
        PdfProcessorConfiguration $pdfProcessorConfiguration,
        XlsxProcessorConfiguration $xlsxProcessorConfiguration,
        DocxProcessorConfiguration $docxProcessorConfiguration,
    ): JsonResponse {
        if (
            !$page->type->usesArtifactHostPreview()
            || $version->page_uid !== $page->uid
            || ($page->type === PageType::Pdf && !$pdfProcessorConfiguration->enabled())
            || ($page->type === PageType::Xlsx && !$xlsxProcessorConfiguration->enabled())
            || ($page->type === PageType::Docx
                && (!$docxProcessorConfiguration->enabled() || !$pdfProcessorConfiguration->enabled()))
        ) {
            abort(404);
        }

        Log::info('artifact_history_preview_url.refreshed', [
            'page_uid' => $page->uid,
            'version_uid' => $version->uid,
        ]);

        return response()
            ->json(['url' => match ($page->type) {
                PageType::Pdf => $pdfArtifactUrl->temporaryHistoryUrl($page, $version),
                PageType::Docx => $docxPreviewUrl->temporaryHistoryUrl($page, $version),
                default => $previewUrl->temporaryHistoryUrl($page, $version),
            }])
            ->header('Cache-Control', 'no-store, private');
    }
}
