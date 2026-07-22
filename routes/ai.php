<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceMcpOrigin;
use App\Http\Middleware\RecordMcpTokenUsage;
use App\Http\Middleware\RejectArtifactHostRuntime;
use App\Http\Middleware\ThrottleMcpRequests;
use App\Mcp\Servers\ArtifactFlowServer;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Mcp\Facades\Mcp;

// Mcp::web() registers GET and DELETE compatibility routes in addition to the
// returned POST route. Put transport-wide controls and stateful exclusions on
// the surrounding group so every generated route stays cookieless and shares
// the pre-authenticated source-IP ceiling. Installation readiness, runtime-role
// separation, and security headers remain inherited from the web group.
Route::middleware([
    'web',
    RejectArtifactHostRuntime::class,
    ThrottleMcpRequests::class,
    EnforceMcpOrigin::class,
])->withoutMiddleware([
    AddQueuedCookiesToResponse::class,
    EncryptCookies::class,
    PreventRequestForgery::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->group(function (): void {
    Mcp::web('/mcp', ArtifactFlowServer::class)
        ->middleware([
            'auth:mcp',
            'throttle:mcp',
            RecordMcpTokenUsage::class,
        ])
        ->name('mcp');
});
