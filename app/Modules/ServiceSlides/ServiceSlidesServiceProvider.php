<?php

namespace App\Modules\ServiceSlides;

use App\Http\Middleware\EnsureTeamMembership;
use App\Modules\ServiceSlides\Console\FormatLibrarySongs;
use App\Modules\ServiceSlides\Console\IndexLibrarySlideText;
use App\Modules\ServiceSlides\Http\Controllers\FileLinkController;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ServiceSlidesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new ServiceSlidesModule);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->commands([FormatLibrarySongs::class, IndexLibrarySlideText::class]);

        Route::middleware(['web', 'auth', 'verified', EnsureTeamMembership::class, 'module:service-slides'])
            ->prefix('{current_team}/slides')
            ->name('service-slides.')
            ->group(__DIR__.'/routes/web.php');

        // Links handed out by MCP tools. They work without a session: the signature and the checks in the
        // controller stand in for signing in.
        Route::middleware(['signed', 'throttle:60,1'])
            ->get('slide-files/{kind}/{id}', FileLinkController::class)
            ->whereIn('kind', ['library', 'deck'])
            ->whereNumber('id')
            ->name('service-slides.file-link');
    }
}
