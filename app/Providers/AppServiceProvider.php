<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Rate Limiters (Laravel 11)
        |--------------------------------------------------------------------------
        */

        // ✅ limiter عام للـ API
        RateLimiter::for('api', function (Request $request) {
            $userId = optional($request->user())->id;
            return Limit::perMinute(120)->by($userId ?: $request->ip());
        });

        // ✅ limiter خاص لتسجيل الدخول
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');
            return Limit::perMinute(10)->by($email ?: $request->ip());
        });

        // ✅ limiter خاص لتجديد التوكن
        RateLimiter::for('refresh', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}