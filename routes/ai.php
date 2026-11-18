<?php

use App\Modules\ServiceSlides\Http\Middleware\AuthenticateMcp;
use App\Modules\ServiceSlides\Mcp\ServiceSlidesServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:30,1')->group(function () {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp/services', ServiceSlidesServer::class)
    ->middleware(AuthenticateMcp::class);
