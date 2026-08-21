<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\PdfArtifactUrl;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

final readonly class PdfDownloadController
{
    public function current(
        Page $page,
        PdfArtifactUrl $artifactUrl,
        PdfProcessorConfiguration $processorConfiguration,
    ): RedirectResponse {
        if (
            !$processorConfiguration->enabled()
            || $page->type !== PageType::Pdf
            || $page->current_version_uid === null
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

        return $this->redirect($page, $version, $artifactUrl);
    }

    public function history(
        Page $page,
        PageVersion $version,
        PdfArtifactUrl $artifactUrl,
        PdfProcessorConfiguration $processorConfiguration,
    ): RedirectResponse {
        if (
            !$processorConfiguration->enabled()
            || $page->type !== PageType::Pdf
            || $version->page_uid !== $page->uid
        ) {
            abort(404);
        }

        return $this->redirect($page, $version, $artifactUrl);
    }

    private function redirect(
        Page $page,
        PageVersion $version,
        PdfArtifactUrl $artifactUrl,
    ): RedirectResponse {
        Log::info('pdf_download_url.issued', [
            'page_uid' => $page->uid,
            'version_uid' => $version->uid,
        ]);

        return redirect()
            ->away($artifactUrl->temporaryDownloadUrl($page, $version))
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
