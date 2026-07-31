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
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class ExternalShareViewerContentController
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

    public function __invoke(Request $request, string $externalShareUid): Response
    {
        $credential = $this->cookies->credential($request, ExternalShareSessionKind::View);
        $windowToken = $request->input('window_token');

        if (
            !$this->sameOrigin->accepts($request)
            || $credential === null
            || !is_string($windowToken)
            || !$this->windowTokens->matches($credential, $windowToken)
        ) {
            return $this->responses->unavailableView();
        }

        $context = $this->views->fromCredential($externalShareUid, $credential);
        $viewer = $context === null ? null : $this->content->forContext($context);

        if ($viewer === null) {
            return $this->responses->unavailableView();
        }

        return $this->responses->secure(response()->view('external-shares.partials.viewer-content', [
            'viewer' => $viewer,
        ]));
    }
}
