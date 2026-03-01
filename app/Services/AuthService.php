<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cookie;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthService
{
    /**
     * محاولة تسجيل الدخول
     */
    public function attemptLogin(array $credentials, bool $remember, Request $request)
    {
        // ✅ guard الافتراضي عادة web
        if (!Auth::guard('web')->attempt($credentials, $remember)) {
            return false;
        }

        /** @var User|null $user */
        $user = User::query()
            ->with('branch')
            ->where('email', (string)($credentials['email'] ?? ''))
            ->first();

        if (!$user) {
            Auth::guard('web')->logout();
            return false;
        }

        if (($user->status ?? null) !== 'active') {
            Auth::guard('web')->logout();
            return 'inactive';
        }

        return $this->generateTokenAndCookie($user, $remember, $request);
    }

    /**
     * توليد توكن + Cookie
     */
    private function generateTokenAndCookie(User $user, bool $remember, Request $request): array
    {
        // ✅ إذا بدك تمنع خروج الأجهزة الأخرى احذف هذا السطر
        $user->tokens()->delete();

        // مدة التوكن/الكوكي
        $minutes = $remember ? (60 * 24 * 30) : 120; // remember=30 يوم, غيره=120 دقيقة
        $expiresAt = now()->addMinutes($minutes);

        // ✅ Sanctum createToken (يدعم expiresAt في نسخ حديثة)
        $tokenResult = $user->createToken('access_token', ['*'], $expiresAt);
        $accessToken = $tokenResult->plainTextToken;

        // إعدادات الكوكي
        $domain = config('session.domain') ?: null; // localhost = null أفضل
        $path   = '/';

        // secure: إذا الطلب مو https => لازم false
        $secure = (bool) config('session.secure', false);
        if (!$request->isSecure()) {
            $secure = false;
        }

        // sameSite
        $sameSite = (string) config('session.same_site', 'lax');
        $sameSite = strtolower($sameSite);

        /**
         * ⚠️ مهم:
         * - cross-site يحتاج SameSite=none + Secure=true (يعني HTTPS)
         * - على http local، SameSite=none رح تنرفض من المتصفح
         */
        if (!$secure && $sameSite === 'none') {
            $sameSite = 'lax';
        }

        $cookie = cookie(
            'access_token',
            $accessToken,
            $minutes,
            $path,
            $domain,
            $secure,
            true,    // HttpOnly
            false,
            $sameSite
        );

        return [
            'user'   => $user,
            // ✅ Cookie-only auth: لا ترجع token للفرونت
            // 'token'  => $accessToken,
            'cookie' => $cookie,
        ];
    }

    /**
     * تجديد التوكن
     */
    public function refreshToken(Request $request): ?array
    {
        try {
            $token = (string) $request->cookie('access_token', '');
            if ($token === '') return null;

            $personalAccessToken = PersonalAccessToken::findToken($token);
            if (!$personalAccessToken) return null;

            // انتهت صلاحية التوكن
            if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
                $personalAccessToken->delete();
                return null;
            }

            $user = $personalAccessToken->tokenable;
            if (!$user) {
                $personalAccessToken->delete();
                return null;
            }

            // ✅ rotate token
            $personalAccessToken->delete();

            // refresh عادة نخليه "remember=true" ليعطي مدة أطول
            return $this->generateTokenAndCookie($user, true, $request);

        } catch (Throwable $e) {
            Log::error("refreshToken error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * تسجيل الخروج
     */
    public function executeLogout(Request $request)
    {
        try {
            // ✅ احذف التوكن حتى لو request->user() مو جاهز
            $token = (string) $request->cookie('access_token', '');
            if ($token !== '') {
                $pat = PersonalAccessToken::findToken($token);
                $uid = $pat?->tokenable_id;

                $pat?->delete();

                if ($uid) {
                    Cache::forget("user_permissions_{$uid}");
                    Cache::increment("auth:permver:user:{$uid}");
                }
            }

            Auth::guard('web')->logout();

        } catch (Throwable $e) {
            Log::warning("executeLogout error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }

        // ✅ امسح cookie بنفس path/domain
        $domain = config('session.domain') ?: null;
        return cookie()->forget('access_token', '/', $domain);
    }
}