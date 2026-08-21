<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\PdfArtifactUrl;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ArtifactPreviewUrlController
{
    public function __invoke(
        Page $page,
        ArtifactPreviewUrl $previewUrl,
        PdfArtifactUrl $pdfArtifactUrl,
        PdfProcessorConfiguration $pdfProcessorConfiguration,
    ): JsonResponse {
        if (
            !$page->type->usesArtifactHostPreview()
            || $page->current_version_uid === null
            || ($page->type === PageType::Pdf && !$pdfProcessorConfiguration->enabled())
        ) {
            abort(404);
        }

        $version = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->first();

        if (!$version instanceof PageVersion) {
            abort(404);
        }

        Log::info('artifact_preview_url.refreshed', [
            'page_uid' => $page->uid,
            'version_uid' => $version->uid,
        ]);

        return response()
            ->json(['url' => $page->type === PageType::Pdf
                ? $pdfArtifactUrl->temporaryCurrentUrl($page, $version)
                : $previewUrl->temporaryUrl($page, $version)])
            ->header('Cache-Control', 'no-store, private');
    }
}
