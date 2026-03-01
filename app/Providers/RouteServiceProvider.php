<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ✅ Rate Limiting ذكي للـ login (email + ip)
        RateLimiter::for('login', function (Request $request) {

            $email = (string) $request->input('email', '');
            $email = strtolower(trim($email)); // للإيميل كافي + آمن

            // مفتاح ذكي: email + ip
            $key = 'login:' . $email . '|' . $request->ip();

            return Limit::perMinute(5)
                ->by($key)
                ->response(function () {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'محاولات كثيرة. حاول بعد قليل.'
                    ], 429);
                });
        });

        // ✅ (اختياري لكن ممتاز) limiter للـ refresh
        RateLimiter::for('refresh', function (Request $request) {
            // على refresh الأفضل نعتمد على ip + user-agent أو ip فقط
            $key = 'refresh:' . $request->ip() . '|' . substr((string) $request->userAgent(), 0, 120);

            // مثال: 20 مرة/دقيقة
            return Limit::perMinute(20)
                ->by($key)
                ->response(function () {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'طلبات كثيرة لتجديد الجلسة. حاول بعد قليل.'
                    ], 429);
                });
        });

        parent::boot();
    }
}