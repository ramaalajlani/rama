<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\{Auth, Cache, Log};
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * محاولة تسجيل الدخول والتحقق من حالة الحساب
     */
    public function attemptLogin(array $credentials, bool $remember, Request $request)
    {
        // محاولة التحقق من البيانات عبر حارس الويب
        if (!Auth::guard('web')->attempt($credentials, $remember)) {
            return false; 
        }

        // جلب المستخدم مع علاقة الفرع لضمان كفاءة الأداء N+1
        $user = User::with('branch')->where('email', $credentials['email'])->first();

        // التحقق من حالة الحساب
        if (!$user->status === 'active') { // افترضنا أن الحقل اسمه status
            Auth::guard('web')->logout();
            return 'inactive';
        }

        // إنشاء التوكن والكوكي
        return $this->generateTokenAndCookie($user);
    }

    /**
     * توليد توكن جديد وكوكي HttpOnly محمية
     */
    private function generateTokenAndCookie($user)
    {
        // إنشاء التوكن مع صلاحية لمدة ساعتين (يمكن تعديلها)
        $tokenResult = $user->createToken('access_token', ['*'], now()->addHours(2));
        $accessToken = $tokenResult->plainTextToken;

        // إعداد الكوكي (Refresh Token)
        $cookie = cookie(
            'refresh_token',
            $accessToken,
            1440, // صالحة لـ 24 ساعة
            '/',
            null,
            config('session.secure'), // تفعيل Secure في حالة HTTPS
            true,  // HttpOnly: حماية ضد XSS
            false,
            'Lax'  // حماية ضد CSRF
        );

        return [
            'user'   => $user,
            'token'  => $accessToken,
            'cookie' => $cookie
        ];
    }

    /**
     * تجديد التوكن بناءً على الكوكي المخزنة
     */
    public function refreshToken(Request $request)
    {
        $token = $request->cookie('refresh_token');
        if (!$token) return null;

        $personalAccessToken = PersonalAccessToken::findToken($token);

        // التحقق من صلاحية التوكن القديم
        if (!$personalAccessToken || ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast())) {
            return null;
        }

        $user = $personalAccessToken->tokenable;

        // حذف التوكن القديم فوراً (زيادة أمان لضمان عدم إعادة استخدامه)
        $personalAccessToken->delete();

        // توليد زوج جديد (Token + Cookie)
        return $this->generateTokenAndCookie($user);
    }

    /**
     * تنفيذ خروج المستخدم وتنظيف الكاش
     */
    public function executeLogout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $user->currentAccessToken()?->delete();
            Cache::forget("user_permissions_{$user->id}");
        }

        Auth::guard('web')->logout();
        return cookie()->forget('refresh_token');
    }
}