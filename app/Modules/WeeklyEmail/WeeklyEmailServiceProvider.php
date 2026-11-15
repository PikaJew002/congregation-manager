<?php

namespace App\Modules\WeeklyEmail;

use App\Http\Middleware\EnsureTeamMembership;
use App\Modules\WeeklyEmail\Console\DispatchDueWeeklyEmails;
use App\Modules\WeeklyEmail\Http\Controllers\UnsubscribeController;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WeeklyEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new WeeklyEmailModule);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->commands([DispatchDueWeeklyEmails::class]);

        Route::middleware(['web', 'auth', 'verified', EnsureTeamMembership::class, 'module:weekly-email'])
            ->prefix('{current_team}/weekly-email')
            ->name('weekly-email.')
            ->group(__DIR__.'/routes/web.php');

        Route::middleware('web')->group(function () {
            Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('weekly-email.unsubscribe');
            Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('weekly-email.unsubscribe.store');
        });
    }
}
