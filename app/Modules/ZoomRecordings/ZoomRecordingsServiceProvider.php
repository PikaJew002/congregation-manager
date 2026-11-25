<?php

namespace App\Modules\ZoomRecordings;

use App\Http\Middleware\EnsureTeamMembership;
use App\Modules\ZoomRecordings\Http\Controllers\MediaWorkerCallbackController;
use App\Modules\ZoomRecordings\Http\Controllers\ZoomWebhookController;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ZoomRecordingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new ZoomRecordingsModule);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        Route::middleware(['web', 'auth', 'verified', EnsureTeamMembership::class, 'module:zoom-recordings'])
            ->prefix('{current_team}/recordings')
            ->name('zoom-recordings.')
            ->group(__DIR__.'/routes/web.php');

        Route::middleware('web')
            ->post('webhooks/zoom/{team:uuid}', ZoomWebhookController::class)
            ->name('zoom-recordings.webhook');

        Route::middleware('web')
            ->post('webhooks/media-worker', MediaWorkerCallbackController::class)
            ->name('zoom-recordings.media-worker');
    }
}
