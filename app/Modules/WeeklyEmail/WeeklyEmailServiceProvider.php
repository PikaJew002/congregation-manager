<?php

namespace App\Modules\WeeklyEmail;

use App\Http\Middleware\EnsureTeamMembership;
use App\Mail\MailChannels\DeliveryEvent;
use App\Modules\WeeklyEmail\Console\CheckEmailSending;
use App\Modules\WeeklyEmail\Console\DispatchDueWeeklyEmails;
use App\Modules\WeeklyEmail\Console\SendDueWeeklyEmails;
use App\Modules\WeeklyEmail\Http\Controllers\UnsubscribeController;
use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Listeners\RecordDeliveryEvent;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->commands([CheckEmailSending::class, DispatchDueWeeklyEmails::class, SendDueWeeklyEmails::class]);

        Event::listen(DeliveryEvent::class, RecordDeliveryEvent::class);

        RateLimiter::for(SendWeeklyEmailJob::RATE_LIMITER, fn (SendWeeklyEmailJob $job) => Limit::perMinute(SendWeeklyEmailJob::MAX_PER_MINUTE)->by('team-'.$job->teamId));

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
