<?php

namespace App\Modules\ServiceSlides;

use App\Http\Middleware\EnsureTeamMembership;
use App\Modules\ServiceSlides\Console\FormatLibrarySongs;
use App\Modules\ServiceSlides\Console\IndexLibrarySlideText;
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
    }
}
