<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\ExternalShareWindowToken;
use App\Application\ExternalSharing\OpenExternalShare;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Http\Support\ExternalShareCookies;
use App\Http\Support\ExternalShareResponses;
use App\Http\Support\ExternalShareSameOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ExternalShareOpenController
{
    public function __construct(
        private OpenExternalShare $open,
        private ExternalShareSameOrigin $sameOrigin,
        private ExternalShareWindowToken $windowTokens,
        private ExternalShareCookies $cookies,
        private ExternalShareResponses $responses,
    ) {
    }

    public function __invoke(Request $request, string $externalShareUid): JsonResponse
    {
        $pendingCredential = $this->cookies->credential(
            $request,
            ExternalShareSessionKind::PendingOpen,
        );
        $csrfToken = $request->input('csrf_token');

        if (
            !$this->sameOrigin->accepts($request)
            || $pendingCredential === null
            || !is_string($csrfToken)
        ) {
            return $this->responses->unavailableJson();
        }

        $issued = $this->open->handle($externalShareUid, $pendingCredential, $csrfToken);

        if ($issued === null) {
            return $this->responses->unavailableJson();
        }

        $response = $this->responses->json([
            'state' => 'viewer',
            'viewer_url' => route('external-shares.viewer', [
                'externalShareUid' => $externalShareUid,
                'externalShareSessionUid' => $issued->session->uid,
            ]),
            'window_token' => $this->windowTokens->issue($issued->credential()),
        ]);
        $this->cookies->attach($response, $request, $externalShareUid, $issued);
        $this->cookies->clearPending($response, $request, $externalShareUid);

        return $response;
    }
}
