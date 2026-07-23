<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
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

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }

    /**
     * Configure rate limits for application workflows that call external services.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('library-assistant', function (Request $request): array {
            $userKey = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(10)->by('library-assistant:user:'.$userKey),
                Limit::perMinute(60)->by('library-assistant:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('library-assistant-confirmation', function (Request $request): Limit {
            return Limit::perMinute(20)->by(
                'library-assistant-confirmation:user:'.($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
