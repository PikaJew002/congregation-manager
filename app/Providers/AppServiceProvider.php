<?php

namespace App\Providers;

use App\Mail\Transport\MailChannelsTransport;
use App\Mcp\AssignMcpCongregation;
use App\Mcp\CongregationContext;
use App\Models\Passport\AuthCode;
use App\Models\Passport\Token;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Http\Middleware\EnsureOauthUserIsVerified;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

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
        $this->configurePassport();
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
            ], [
                'transactional' => $config['transactional'] ?? null,
                'tracking' => $config['tracking'] ?? null,
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

    protected function configurePassport(): void
    {
        Passport::tokensCan([
            'mcp:use' => 'Use Congregation Manager tools for one congregation',
        ]);
        Passport::tokensExpireIn(CarbonInterval::days(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(180));
        Passport::useAuthCodeModel(AuthCode::class);
        Passport::useTokenModel(Token::class);
        Passport::authorizationView(function (array $parameters) {
            $user = $parameters['user'];
            $teams = $user instanceof User
                ? $user->teams()->orderBy('name')->get()->filter(
                    fn (Team $team): bool => app(CongregationContext::class)->canConnect($user, $team),
                )->values()
                : collect();

            return response()->view('mcp.authorize', [
                ...$parameters,
                'teams' => $teams,
            ]);
        });

        Route::getRoutes()->getByName('passport.authorizations.authorize')
            ?->middleware(EnsureOauthUserIsVerified::class);
        Route::getRoutes()->getByName('passport.authorizations.approve')
            ?->middleware([EnsureOauthUserIsVerified::class, AssignMcpCongregation::class]);
        Route::getRoutes()->getByName('passport.authorizations.deny')
            ?->middleware(EnsureOauthUserIsVerified::class);
    }
}
