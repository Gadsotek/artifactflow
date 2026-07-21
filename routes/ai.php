<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceMcpOrigin;
use App\Http\Middleware\RecordMcpTokenUsage;
use App\Http\Middleware\RejectArtifactHostRuntime;
use App\Http\Middleware\ThrottleMcpRequests;
use App\Mcp\Servers\ArtifactFlowServer;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('web')->group(function (): void {
    Mcp::web('/mcp', ArtifactFlowServer::class)
        ->middleware([
            RejectArtifactHostRuntime::class,
            ThrottleMcpRequests::class,
            EnforceMcpOrigin::class,
            'auth:mcp',
            'throttle:mcp',
            RecordMcpTokenUsage::class,
        ])
        ->withoutMiddleware([PreventRequestForgery::class])
        ->name('mcp');
});
