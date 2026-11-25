<?php

use App\Mcp\AuthenticateMcp;
use App\Mcp\CongregationServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:30,1')->group(function () {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', CongregationServer::class)
    ->middleware(AuthenticateMcp::class);

// Earlier connections were made to the slides-only address. It now serves the
// same congregation server, so those keep working.
Mcp::web('/mcp/services', CongregationServer::class)
    ->middleware(AuthenticateMcp::class);
