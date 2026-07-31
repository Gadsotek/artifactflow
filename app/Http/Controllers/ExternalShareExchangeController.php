<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\ExchangeExternalShare;
use App\Application\ExternalSharing\ExternalShareWindowToken;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Http\Support\ExternalShareCookies;
use App\Http\Support\ExternalShareResponses;
use App\Http\Support\ExternalShareSameOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ExternalShareExchangeController
{
    public function __construct(
        private ExchangeExternalShare $exchange,
        private ExternalShareSameOrigin $sameOrigin,
        private ExternalShareWindowToken $windowTokens,
        private ExternalShareCookies $cookies,
        private ExternalShareResponses $responses,
    ) {
    }

    public function __invoke(Request $request, string $externalShareUid): JsonResponse
    {
        $secret = $request->input('secret');

        if (!$this->sameOrigin->accepts($request) || !is_string($secret)) {
            return $this->responses->unavailableJson();
        }

        $result = $this->exchange->handle($externalShareUid, $secret);

        if ($result === null) {
            return $this->responses->unavailableJson();
        }

        if ($result->issuedSession->session->kind === ExternalShareSessionKind::View->value) {
            $response = $this->responses->json([
                'state' => 'viewer',
                'viewer_url' => route('external-shares.viewer', [
                    'externalShareUid' => $externalShareUid,
                ]),
                'window_token' => $this->windowTokens->issue(
                    $result->issuedSession->credential(),
                ),
            ]);
        } else {
            $response = $this->responses->json([
                'state' => 'confirmation',
                'csrf_token' => $result->csrfToken,
                'artifact' => [
                    'title' => $result->page->title,
                    'mode' => $result->share->mode->value,
                    'expires_at' => $result->share->expires_at?->toISOString(),
                    'acknowledgement_required' => $result->acknowledgementRequired,
                ],
            ]);
        }

        $this->cookies->attach(
            $response,
            $request,
            $externalShareUid,
            $result->issuedSession,
        );

        return $response;
    }
}
