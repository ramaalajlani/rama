<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use App\Http\Requests\StoreUserRequest;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->middleware('auth:sanctum');
        $this->userService = $userService;
    }


    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->getUsersForManager();

        return response()->json([
            'status' => 'success',
            'data'   => $users
        ]);
    }


    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $this->userService->createUser($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'تم إنشاء الحساب وتعيين الصلاحيات بنجاح'
        ], 201);
    }
}