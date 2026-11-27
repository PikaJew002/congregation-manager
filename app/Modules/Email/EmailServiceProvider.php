<?php

namespace App\Modules\Email;

use App\Http\Middleware\EnsureTeamMembership;
use App\Mail\MailChannels\DeliveryEvent;
use App\Modules\Email\Console\CheckEmailSending;
use App\Modules\Email\Console\DispatchDueEmails;
use App\Modules\Email\Console\SendDueEmails;
use App\Modules\Email\Http\Controllers\UnsubscribeController;
use App\Modules\Email\Jobs\SendEmailJob;
use App\Modules\Email\Listeners\RecordDeliveryEvent;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(ModuleRegistry::class)->register(new EmailModule);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->commands([CheckEmailSending::class, DispatchDueEmails::class, SendDueEmails::class]);

        Event::listen(DeliveryEvent::class, RecordDeliveryEvent::class);

        RateLimiter::for(SendEmailJob::RATE_LIMITER, fn (SendEmailJob $job) => Limit::perMinute(SendEmailJob::MAX_PER_MINUTE)->by('team-'.$job->teamId));

        Route::middleware(['web', 'auth', 'verified', EnsureTeamMembership::class, 'module:email'])
            ->prefix('{current_team}/email')
            ->name('email.')
            ->group(__DIR__.'/routes/web.php');

        // Bookmarks from when this was the Weekly Email module.
        Route::middleware('web')->get('{current_team}/weekly-email/{path?}', function (string $current_team, ?string $path = null) {
            $target = match (true) {
                $path === 'recipients' => '/recipients',
                $path !== null && preg_match('#^runs(/\d+)?$#', $path) === 1 => '/'.$path,
                default => '',
            };

            return redirect('/'.$current_team.'/email'.$target, 301);
        })->where('path', '.*');

        Route::middleware('web')->group(function () {
            Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('email.unsubscribe');
            Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])->name('email.unsubscribe.store');
        });
    }
}
