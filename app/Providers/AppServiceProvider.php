<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Every catalog permission is an ability (can:units.view, @can('units.view')),
        // answered from the user's role and overrides. Other abilities go to policies.
        Gate::before(fn (User $user, string $ability) => Permissions::exists($ability) ? $user->hasPermission($ability) : null);

        // Public patient survey: a few submissions per visitor, then a polite refusal
        // that keeps the answers given.
        RateLimiter::for('public-survey', function (Request $request) {
            $refuse = fn () => redirect()->route('survey.show')->withInput($request->except('_token'))
                ->with('error', 'Terlalu banyak pengiriman dari perangkat ini. Silakan coba lagi beberapa saat lagi.');

            return [
                Limit::perMinute(max(1, (int) config('survey.per_minute')))->by('minute:'.$request->ip())->response($refuse),
                Limit::perHour(max(1, (int) config('survey.per_hour')))->by('hour:'.$request->ip())->response($refuse),
            ];
        });
    }
}
