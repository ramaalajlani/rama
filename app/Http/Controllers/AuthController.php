<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attemptLogin(
            $request->only('email', 'password'),
            $request->boolean('remember'),
            $request
        );

        if ($result === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'بيانات الدخول غير صحيحة.'
            ], 401);
        }

        if ($result === 'inactive') {
            return response()->json([
                'status' => 'error',
                'message' => 'هذا الحساب موقوف حالياً.'
            ], 403);
        }

        $user = $result['user'];

        return response()->json([
            'status'  => 'success',
            'message' => 'مرحباً بك مجدداً، ' . $user->name,
            'data'    => [
                'access_token' => $result['token'],
                'user' => [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'branch_id'   => $user->branch_id,
                    'branch_name' => $user->branch?->name,
                    'roles'       => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ]
            ]
        ], 200)->withCookie($result['cookie']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $result = $this->authService->refreshToken($request);

        if (!$result) {
            return response()->json([
                'status' => 'error',
                'message' => 'انتهت الجلسة، يرجى تسجيل الدخول.'
            ], 401);
        }

        // نرسل التوكن الجديد في الرد والكوكي الجديد في الهيدر
        return response()->json([
            'status'       => 'success',
            'access_token' => $result['token']
        ], 200)->withCookie($result['cookie']);
    }

    public function logout(Request $request): JsonResponse
    {
        $forgetCookie = $this->authService->executeLogout($request);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل الخروج بنجاح.'
        ], 200)->withCookie($forgetCookie);
    }
}