<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use App\Http\Requests\StoreUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->middleware('auth:sanctum');
        $this->userService = $userService;
    }

    /**
     * عرض قائمة المستخدمين (الموظفين)
     * تحسين: جلب الحقول الأساسية فقط لمنع ثقل الـ JSON
     */
    public function index(): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            /**
             * تأكد أن الخدمة (UserService) تستخدم paginate 
             * وأنها لا تجلب علاقة reservations لكل موظف داخل الـ index
             */
            $users = $this->userService->getUsersForManager();

            return response()->json([
                'status' => 'success',
                'data'   => $users
            ]);
        } catch (Exception $e) {
            Log::error("User Index Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'تعذر جلب قائمة الموظفين.'
            ], 500);
        }
    }

    /**
     * إنشاء حساب موظف جديد وتعيين الصلاحيات
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', User::class);

            // نقوم بإنشاء المستخدم عبر الخدمة لضمان تنفيذ منطق الـ Roles بشكل صحيح
            $user = $this->userService->createUser($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء الحساب وتعيين الصلاحيات بنجاح',
                'data'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(), // نرسل الأدوار فقط بدلاً من كائن المستخدم كاملاً
                ]
            ], 201);

        } catch (Exception $e) {
            Log::error("User Store Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'فشل إنشاء الحساب: ' . $e->getMessage()
            ], 400);
        }
    }
}