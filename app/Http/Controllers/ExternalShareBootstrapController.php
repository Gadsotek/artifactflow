<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class ExternalShareBootstrapController
{
    public function __invoke(string $externalShareUid): Response
    {
        return response()
            ->view('external-shares.bootstrap', [
                'externalShareUid' => $externalShareUid,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
