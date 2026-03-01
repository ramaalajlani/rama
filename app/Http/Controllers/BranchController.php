<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BranchService;
use App\Http\Requests\StoreBranchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class BranchController extends Controller
{
    protected BranchService $branchService;

    public function __construct(BranchService $branchService)
    {
        $this->middleware('auth:sanctum');
        $this->branchService = $branchService;
    }

    /**
     * Cache helper: tags لو مدعومة، وإلا store عادي
     */
    private function branchesCacheStore()
    {
        if (method_exists(Cache::getStore(), 'tags')) {
            return Cache::tags(['branches']);
        }
        return Cache::store();
    }

    /**
     * Versioning fallback للكاش إذا ما في tags
     */
    private function branchesCacheVersion(): int
    {
        return (int) Cache::get('branches:version', 1);
    }

    private function bumpBranchesCacheVersion(): void
    {
        // لو المفتاح مو موجود يبدأ من 1
        if (!Cache::has('branches:version')) {
            Cache::put('branches:version', 1);
        }
        Cache::increment('branches:version');
    }

    private function invalidateBranchesCache(): void
    {
        // tags أفضل، fallback versioning
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags(['branches'])->flush();
        } else {
            $this->bumpBranchesCacheVersion();
        }
    }

    /**
     * عرض الفروع مع الإحصائيات
     * أداء عالي: Pagination + select + cache
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Branch::class);

            $perPage = (int) $request->query('per_page', 20);
            if ($perPage < 1) $perPage = 20;
            if ($perPage > 100) $perPage = 100;

            $onlyActive = $request->boolean('active_only', false);

            $page = (int) $request->query('page', 1);
            if ($page < 1) $page = 1;

            $version = $this->branchesCacheVersion();

            $cacheKey = "branches:index:v={$version}:per={$perPage}:active=" . ($onlyActive ? '1' : '0') . ":page={$page}";

            $store = $this->branchesCacheStore();
            $branches = $store->remember($cacheKey, now()->addSeconds(30), function () use ($perPage, $onlyActive) {
                return $this->branchService->getAllBranchesWithStats($perPage, $onlyActive);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $branches,
            ]);

        } catch (Exception $e) {
            Log::error("Branch Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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

            // امسح كاش الفروع فقط
            $this->invalidateBranchesCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء الفرع بنجاح',
                'data'    => [
                    'id'           => $branch->id,
                    'name'         => $branch->name,
                    'city'         => $branch->city,
                    'manager_name' => $branch->manager_name,
                    'address'      => $branch->address,
                    'phone'        => $branch->phone,
                    'status'       => $branch->status,
                    'created_at'   => $branch->created_at,
                ],
            ], 201);

        } catch (Exception $e) {
            Log::warning("Branch Store Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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

            $branch = $this->branchService->updateBranch($branch, $request->validated());

            // امسح كاش الفروع فقط
            $this->invalidateBranchesCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث بيانات الفرع بنجاح',
                'data'    => [
                    'id'           => $branch->id,
                    'name'         => $branch->name,
                    'city'         => $branch->city,
                    'manager_name' => $branch->manager_name,
                    'address'      => $branch->address,
                    'phone'        => $branch->phone,
                    'status'       => $branch->status,
                    'updated_at'   => $branch->updated_at,
                ],
            ]);

        } catch (Exception $e) {
            Log::warning("Branch Update Error: " . $e->getMessage(), ['branch_id' => $branch->id, 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'error',
                'message' => 'فشل تحديث البيانات: ' . $e->getMessage()
            ], 400);
        }
    }
}