<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactContentReader;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Domain\PageCatalog\ArtifactPreviewPurpose;
use App\Domain\PageCatalog\PageType;
use App\Http\Support\ArtifactSandboxResponder;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class ArtifactPreviewController
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private ArtifactSandboxResponder $responder,
    ) {
    }

    public function __invoke(
        Request $request,
        string $pageUid,
        string $versionUid,
        ArtifactPreviewUrl $previewUrl,
    ): Response {
        if (!$previewUrl->requestMatchesArtifactOrigin($request)) {
            $this->rejectNotFound('wrong_origin', $pageUid, $versionUid, [
                'request_origin' => $request->getSchemeAndHttpHost(),
            ]);
        }

        $page = Page::query()->find($pageUid);
        $version = PageVersion::query()->find($versionUid);

        if (!$page instanceof Page || !$version instanceof PageVersion) {
            $this->rejectNotFound('missing_record', $pageUid, $versionUid);
        }

        // Uniform 404: the signature covers the page's preview_access_revision, so it
        // cannot be checked before the records are loaded. Responding 404 here (not 403)
        // keeps invalid-signature and missing-record indistinguishable, so an
        // unauthenticated holder of leaked UIDs cannot probe whether records exist.
        $purpose = ArtifactPreviewPurpose::tryFrom(
            $this->queryString($request, 'purpose') ?? ArtifactPreviewPurpose::Current->value,
        );

        if (!$purpose instanceof ArtifactPreviewPurpose || !$previewUrl->hasValidSignature(
            $page,
            $versionUid,
            $this->queryString($request, 'expires'),
            $this->queryString($request, 'signature'),
            $purpose,
        )) {
            $this->rejectNotFound('invalid_signature', $pageUid, $versionUid);
        }

        if (
            $page->type !== PageType::HtmlArtifact
            || $version->page_uid !== $page->uid
            || ($purpose === ArtifactPreviewPurpose::Current && $page->current_version_uid !== $version->uid)
        ) {
            $this->rejectNotFound('invalid_version', $pageUid, $versionUid);
        }

        if ($this->responder->isTopLevelNavigation($request)) {
            $this->logRejection('top_level_navigation', $page->uid, $versionUid);

            return $this->responder->topLevelNavigationNotice($this->openInAppUrl($page->uid, $version->uid, $purpose));
        }

        $content = $this->contentReader->read($version->content_storage_path);

        if ($content === null) {
            $this->rejectNotFound('missing_storage_content', $pageUid, $versionUid);
        }

        Log::info('artifact_preview.served', [
            'page_uid' => $page->uid,
            'purpose' => $purpose->value,
            'version_uid' => $version->uid,
        ]);

        return $this->responder->document($content, recoveryEnabled: true);
    }

    private function openInAppUrl(
        string $pageUid,
        string $versionUid,
        ArtifactPreviewPurpose $purpose,
    ): ?string {
        // The app origin the recovery notice links back to is resolved by the shared
        // responder (see ArtifactSandboxResponder::appOrigin) so the saved- and draft-preview
        // notices stay consistent, and neither links back at the artifact host that 404s.
        $appOrigin = rtrim($this->responder->appOrigin(), '/');

        if ($appOrigin === '') {
            return null;
        }

        if ($purpose === ArtifactPreviewPurpose::History) {
            return $appOrigin . '/pages/' . $pageUid . '/versions/' . $versionUid;
        }

        return $appOrigin . '/pages/' . $pageUid;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, string> $context
     */
    private function rejectNotFound(string $reason, string $pageUid, string $versionUid, array $context = []): never
    {
        $this->logRejection($reason, $pageUid, $versionUid, $context);
        abort(404);
    }

    /**
     * @param array<string, string> $context
     */
    private function logRejection(string $reason, string $pageUid, string $versionUid, array $context = []): void
    {
        Log::warning('artifact_preview.rejected', [
            'page_uid' => $pageUid,
            'reason' => $reason,
            'version_uid' => $versionUid,
            ...$context,
        ]);
    }
}
