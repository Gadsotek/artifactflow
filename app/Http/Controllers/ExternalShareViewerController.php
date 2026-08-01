<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\ExternalShareResponses;
use Illuminate\Http\Response;

final readonly class ExternalShareViewerController
{
    public function __construct(
        private ExternalShareResponses $responses,
    ) {
    }

    public function __invoke(
        string $externalShareUid,
        string $externalShareSessionUid,
    ): Response {
        return $this->responses->secure(response()->view('external-shares.viewer', [
            'externalShareUid' => $externalShareUid,
            'externalShareSessionUid' => $externalShareSessionUid,
        ]));
    }
}
