<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use App\Http\Requests\StoreUserRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class UserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->middleware('auth:sanctum');
        $this->userService = $userService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', User::class);

            $perPage = (int)$request->get('per_page', 20);
            if ($perPage < 1) $perPage = 20;
            if ($perPage > 100) $perPage = 100;

            $filters = [
                'branch_id' => $request->filled('branch_id') ? (int)$request->branch_id : null,
                'status'    => $request->filled('status') ? (string)$request->status : null,
                'q'         => $request->filled('q') ? trim((string)$request->q) : null,
                'per_page'  => $perPage,
            ];

            $users = $this->userService->getUsersForManager($filters);

            return response()->json([
                'status' => 'success',
                'data'   => $users
            ]);
        } catch (Exception $e) {
            Log::error("User Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'error',
                'message' => 'تعذر جلب قائمة الموظفين.'
            ], 500);
        }
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', User::class);

            $result = $this->userService->createUser($request->validated());

            /** @var User $user */
            $user = $result['user'];
            $roles = $result['roles'] ?? [];

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء الحساب وتعيين الصلاحيات بنجاح',
                'data'    => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'branch_id' => $user->branch_id,
                    'status'    => $user->status,
                    'roles'     => $roles,
                ]
            ], 201);

        } catch (Exception $e) {
            Log::error("User Store Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'error',
                'message' => 'فشل إنشاء الحساب: ' . $e->getMessage()
            ], 400);
        }
    }
}