<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\ExternalArtifactPreviewUrl;
use App\Application\ExternalSharing\ResolveExternalShareView;
use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\RasterImageInspector;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Http\Support\ArtifactSandboxResponder;
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
        private RasterImageInspector $imageInspector,
        private ArtifactSandboxResponder $responder,
    ) {
    }

    public function __invoke(
        Request $request,
        string $externalShareUid,
        string $sessionUid,
        string $pageUid,
        string $versionUid,
    ): Response {
        $context = $this->views->fromSessionUid($externalShareUid, $sessionUid);
        $version = PageVersion::query()->find($versionUid);

        if (
            !$this->urls->requestMatchesArtifactOrigin($request)
            || $context === null
            || !$version instanceof PageVersion
            || $context->page->uid !== $pageUid
            || $context->page->current_version_uid !== $version->uid
            || $version->page_uid !== $context->page->uid
            || !$context->page->type->usesArtifactHostPreview()
            || !$this->urls->hasValidSignature(
                $context,
                $versionUid,
                $this->queryString($request, 'expires'),
                $this->queryString($request, 'signature'),
            )
        ) {
            abort(404);
        }

        if ($this->responder->isTopLevelNavigation($request)) {
            return $this->responder->topLevelNavigationNotice(null);
        }

        $content = $this->contentReader->read($version->content_storage_path);

        if ($content === null) {
            abort(404);
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
                abort(404);
            }

            return $this->responder->imageDocument($content, $image->mediaType);
        }

        return $this->responder->document($content, recoveryEnabled: true);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
