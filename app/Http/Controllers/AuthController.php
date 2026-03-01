<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * تجهيز إعدادات الكوكي حسب config + نوع الطلب
     */
    private function cookieOptions(Request $request): array
    {
        $domain   = config('session.domain');                 // null غالباً على localhost
        $sameSite = (string) config('session.same_site', 'lax');

        // secure: لوكال http => لازم false
        $secure = (bool) config('session.secure', false);
        if (!$request->isSecure()) {
            $secure = false;
        }

        // لو sameSite none بدون https رح يرفضه المتصفح
        if (!$secure && strtolower($sameSite) === 'none') {
            $sameSite = 'Lax';
        }

        return [$domain, $secure, $sameSite];
    }

    /**
     * POST /api/login
     * سريع جداً: لا يرجّع permissions
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $remember = (bool) $request->boolean('remember', false);

            $result = $this->authService->attemptLogin(
                $request->only('email', 'password'),
                $remember,
                $request
            );

            if ($result === false) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'بيانات الدخول غير صحيحة.'
                ], 401);
            }

            if ($result === 'inactive') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'هذا الحساب موقوف حالياً.'
                ], 403);
            }

            /** @var User $user */
            $user = $result['user'];
            $user->loadMissing(['branch:id,name']);

            $roles = $user->getRoleNames()->values();

            // cookie auth flag (للواجهة فقط)
            $rememberMinutes = $remember ? (60 * 24 * 30) : (60 * 24);

            [$domain, $secure, $sameSite] = $this->cookieOptions($request);

            $authFlagCookie = cookie(
                'auth',
                '1',
                $rememberMinutes,
                '/',
                $domain,
                $secure,
                false,   // not httpOnly (الواجهة ممكن تحتاج تقراه)
                false,
                $sameSite
            );

            $response = response()->json([
                'status'  => 'success',
                'message' => 'مرحباً بك مجدداً، ' . $user->name,
                'data'    => [
                    'user' => [
                        'id'          => $user->id,
                        'name'        => $user->name,
                        'email'       => $user->email,
                        'branch_id'   => $user->branch_id,
                        'branch_name' => $user->branch?->name,
                        'roles'       => $roles,
                    ]
                ]
            ], 200)->withCookie($authFlagCookie);

            // ✅ أضف access_token cookie فقط إذا موجود
            if (is_array($result) && !empty($result['cookie'])) {
                $response = $response->withCookie($result['cookie']);
            } else {
                Log::warning('AuthService attemptLogin did not return expected access_token cookie payload.');
            }

            return $response;

        } catch (Throwable $e) {
            Log::error("Auth login error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'حدث خطأ أثناء تسجيل الدخول.'
            ], 500);
        }
    }

    /**
     * GET /api/auth/me
     * يرجّع user + roles + permissions (cached + versioned)
     */
    public function me(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'غير مصرح.'
                ], 401);
            }

            $user->loadMissing(['branch:id,name']);

            $verKey = "auth:permver:user:{$user->id}";
            $ver = (int) Cache::get($verKey, 1);

            $cacheKey = "auth:me:user:{$user->id}:v{$ver}";

            $payload = Cache::remember($cacheKey, now()->addHours(6), function () use ($user) {
                $roles = $user->getRoleNames()->values();
                $perms = $user->getAllPermissions()->pluck('name')->values();

                return [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'branch_id'   => $user->branch_id,
                    'branch_name' => $user->branch?->name,
                    'roles'       => $roles,
                    'permissions' => $perms,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => ['user' => $payload]
            ]);

        } catch (Throwable $e) {
            Log::error("Auth me error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'تعذر جلب بيانات المستخدم.'
            ], 500);
        }
    }

    /**
     * POST /api/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->refreshToken($request);

            if (!$result) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'انتهت الجلسة، يرجى تسجيل الدخول.'
                ], 401);
            }

            [$domain, $secure, $sameSite] = $this->cookieOptions($request);

            $authFlagCookie = cookie(
                'auth',
                '1',
                60 * 24 * 30,
                '/',
                $domain,
                $secure,
                false,
                false,
                $sameSite
            );

            $response = response()->json([
                'status'  => 'success',
                'message' => 'تم تجديد الجلسة بنجاح.',
            ], 200)->withCookie($authFlagCookie);

            if (is_array($result) && !empty($result['cookie'])) {
                $response = $response->withCookie($result['cookie']);
            } else {
                Log::warning('AuthService refreshToken did not return expected access_token cookie payload.');
            }

            return $response;

        } catch (Throwable $e) {
            Log::error("Auth refresh error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'فشل تجديد الجلسة.'
            ], 500);
        }
    }

    /**
     * POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $forgetCookie = $this->authService->executeLogout($request);

            $uid = $request->user()?->id;
            if ($uid) {
                Cache::increment("auth:permver:user:{$uid}");
            }

            $forgetAuthFlag = Cookie::forget('auth', '/', config('session.domain'));

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل الخروج بنجاح.'
            ], 200)
                ->withCookie($forgetCookie)
                ->withCookie($forgetAuthFlag);

        } catch (Throwable $e) {
            Log::error("Auth logout error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'فشل تسجيل الخروج.'
            ], 500);
        }
    }
}