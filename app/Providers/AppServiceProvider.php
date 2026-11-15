<?php

namespace App\Providers;

use App\Mail\Transport\MailChannelsTransport;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        foreach (config('modules.providers', []) as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMail();
        $this->configureModuleAccess();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureMail(): void
    {
        Mail::extend('mailchannels', function (array $config) {
            return new MailChannelsTransport((string) ($config['key'] ?? ''), [
                'domain' => $config['dkim_domain'] ?? null,
                'selector' => $config['dkim_selector'] ?? null,
                'private_key' => $config['dkim_private_key'] ?? null,
            ]);
        });
    }

    protected function configureModuleAccess(): void
    {
        Gate::define('use-module', function (User $user, string $key, $team = null) {
            $team ??= $user->currentTeam;

            if (! $team) {
                return false;
            }

            return app(ModuleAccess::class)->canUse($user, $team, $key);
        });
    }
}
