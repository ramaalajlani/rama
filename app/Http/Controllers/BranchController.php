<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BranchService;
use App\Http\Requests\StoreBranchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class BranchController extends Controller
{
    protected $branchService;

    public function __construct(BranchService $branchService)
    {
        // تأكد من وجود الوسيط لحماية المسارات
        $this->middleware('auth:sanctum');
        $this->branchService = $branchService;
    }

    /**
     * عرض الفروع مع الإحصائيات
     */
    public function index(): JsonResponse
    {
        try {
            $this->authorize('viewAny', Branch::class);
            
            // جلب الفروع مع الإحصائيات (مثل عدد الغرف المشغولة/المتاحة)
            $branches = $this->branchService->getAllBranchesWithStats();
            
            /**
             * ملاحظة أمنية: 
             * تأكد أن الـ BranchService يستخدم simplePaginate أو select 
             * لجلب الحقول الضرورية فقط لتجنب ثقل البيانات.
             */
            return response()->json([
                'status' => 'success',
                'data'   => $branches
            ]);

        } catch (Exception $e) {
            Log::error("Branch Index Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'تعذر جلب بيانات الفروع حالياً.'
            ], 500);
        }
    }

    /**
     * إنشاء فرع جديد
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', Branch::class);

            $branch = $this->branchService->createBranch($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء الفرع بنجاح',
                'data'    => $branch->makeHidden(['users', 'reservations']) // كسر الحلقة هنا يدوياً للتأكيد
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'فشل إنشاء الفرع: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * تحديث بيانات الفرع
     */
    public function update(StoreBranchRequest $request, Branch $branch): JsonResponse
    {
        try {
            $this->authorize('update', $branch);

            $this->branchService->updateBranch($branch, $request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث بيانات الفرع بنجاح'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'فشل تحديث البيانات: ' . $e->getMessage()
            ], 400);
        }
    }
}