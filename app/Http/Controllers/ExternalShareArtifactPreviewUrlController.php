<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\ExternalShareViewerContent;
use App\Application\ExternalSharing\ExternalShareWindowToken;
use App\Application\ExternalSharing\ResolveExternalShareView;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Http\Support\ExternalShareCookies;
use App\Http\Support\ExternalShareResponses;
use App\Http\Support\ExternalShareSameOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ExternalShareArtifactPreviewUrlController
{
    public function __construct(
        private ResolveExternalShareView $views,
        private ExternalShareViewerContent $content,
        private ExternalShareWindowToken $windowTokens,
        private ExternalShareCookies $cookies,
        private ExternalShareSameOrigin $sameOrigin,
        private ExternalShareResponses $responses,
    ) {
    }

    public function __invoke(Request $request, string $externalShareUid): JsonResponse
    {
        $credential = $this->cookies->credential($request, ExternalShareSessionKind::View);
        $windowToken = $request->input('window_token');

        if (
            !$this->sameOrigin->accepts($request)
            || $credential === null
            || !is_string($windowToken)
            || !$this->windowTokens->matches($credential, $windowToken)
        ) {
            return $this->responses->unavailableJson();
        }

        $context = $this->views->fromCredential($externalShareUid, $credential);
        $viewer = $context === null ? null : $this->content->forContext($context);

        if ($viewer === null || $viewer->artifactPreviewUrl === null) {
            return $this->responses->unavailableJson();
        }

        return $this->responses->json(['url' => $viewer->artifactPreviewUrl]);
    }
}
