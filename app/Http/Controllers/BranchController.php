<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BranchService;
use App\Http\Requests\StoreBranchRequest;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    protected $branchService;

    public function __construct(BranchService $branchService)
    {
        $this->middleware('auth:sanctum');
        $this->branchService = $branchService;
    }

 
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);
        
        $branches = $this->branchService->getAllBranchesWithStats();
        
        return response()->json([
            'status' => 'success',
            'data'   => $branches
        ]);
    }


    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $branch = $this->branchService->createBranch($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'تم إنشاء الفرع بنجاح',
            'data'    => $branch
        ], 201);
    }


    public function update(StoreBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorize('update', $branch);

        $this->branchService->updateBranch($branch, $request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تحديث بيانات الفرع بنجاح'
        ]);
    }
}