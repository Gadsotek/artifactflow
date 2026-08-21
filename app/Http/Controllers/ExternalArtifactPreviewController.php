<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\ExternalArtifactPreviewUrl;
use App\Application\ExternalSharing\ExternalShareViewContext;
use App\Application\ExternalSharing\ResolveExternalShareView;
use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\PdfArtifactContentReader;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Application\PageCatalog\RasterImageInspector;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Http\Support\ArtifactSandboxResponder;
use App\Http\Support\PdfArtifactResponder;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class ExternalArtifactPreviewController
{
    public function __construct(
        private ResolveExternalShareView $views,
        private ExternalArtifactPreviewUrl $urls,
        private ArtifactContentReader $contentReader,
        private PdfArtifactContentReader $pdfContentReader,
        private RasterImageInspector $imageInspector,
        private ArtifactSandboxResponder $responder,
        private PdfArtifactResponder $pdfResponder,
        private PdfProcessorConfiguration $pdfConfiguration,
    ) {
    }

    public function __invoke(
        Request $request,
        string $externalShareUid,
        string $sessionUid,
        string $pageUid,
        string $versionUid,
    ): Response {
        if (!$this->urls->requestMatchesArtifactOrigin($request)) {
            abort(404);
        }

        $response = $this->views->withSessionUid(
            $externalShareUid,
            $sessionUid,
            function (ExternalShareViewContext $context) use (
                $request,
                $pageUid,
                $versionUid,
            ): ?Response {
                $version = PageVersion::query()->find($versionUid);

                if (
                    !$version instanceof PageVersion
                    || $context->page->uid !== $pageUid
                    || $context->page->current_version_uid !== $version->uid
                    || $version->page_uid !== $context->page->uid
                    || ($context->page->type === PageType::Pdf && !$this->pdfConfiguration->enabled())
                    || !$context->page->type->usesArtifactHostPreview()
                    || !$this->urls->hasValidSignature(
                        $context,
                        $versionUid,
                        $this->queryString($request, 'expires'),
                        $this->queryString($request, 'signature'),
                    )
                ) {
                    return null;
                }

                if ($this->responder->isTopLevelNavigation($request)) {
                    return $this->responder->topLevelNavigationNotice(null);
                }

                $content = $context->page->type === PageType::Pdf
                    ? $this->pdfContentReader->read($version)
                    : $this->contentReader->read($version->content_storage_path);

                if ($content === null) {
                    return null;
                }

                Log::info('external_artifact_preview.served', [
                    'external_share_uid' => $context->share->uid,
                    'page_uid' => $context->page->uid,
                    'version_uid' => $version->uid,
                ]);

                if ($context->page->type === PageType::Image) {
                    try {
                        $image = $this->imageInspector->inspectStored($content);
                    } catch (DomainRuleViolation) {
                        return null;
                    }

                    return $this->responder->imageDocument($content, $image->mediaType);
                }

                if ($context->page->type === PageType::Pdf) {
                    return $this->pdfResponder->inline($content, $context->page, $version);
                }

                return $this->responder->document($content, recoveryEnabled: true);
            },
        );

        if (!$response instanceof Response) {
            abort(404);
        }

        return $response;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
