<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Services\{GuestService, SecurityService};
use App\Http\Requests\StoreGuestRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, DB};
use Symfony\Component\HttpKernel\Exception\HttpException;

class GuestController extends Controller
{
    protected $guestService;
    protected $securityService;

    public function __construct(GuestService $guestService, SecurityService $securityService)
    {
        $this->middleware('auth:sanctum');
        $this->guestService = $guestService;
        $this->securityService = $securityService;
    }

    /**
     * عرض النزلاء (HQ Admin يرى الجميع، الموظف يرى من زاروا فرعه)
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        $user = $request->user();

        $query = Guest::query();

        // عزل البيانات: إذا لم يكن المستخدم من الإدارة المركزية
        if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
            $query->whereHas('reservations', function ($q) use ($user) {
                $q->where('branch_id', $user->branch_id);
            });
        }

        $guests = $query->with(['latestReservation'])->latest()->paginate(15);
        
        return response()->json([
            'status' => 'success',
            'data'   => $guests
        ]);
    }

    /**
     * البحث الذكي: يقوم بالرصد اللحظي للـ Blacklist أثناء الاستعلام
     */
    public function search(Request $request): JsonResponse
    {
        $queryText = $request->get('q');

        if (strlen($queryText) < 3) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $results = $this->guestService->searchGuests($queryText);

        $enhancedResults = $results->map(function ($guest) {
            // فحص أمني صامت (Silent Security Match)
            $securityHashes = [
                'identity_hash' => $guest->national_id_hash,
                'triple_check'  => $guest->full_security_hash
            ];
            
            $match = $this->securityService->checkAgainstBlacklist($securityHashes);
            
            return [
                'id'           => $guest->id,
                'full_name'    => $guest->first_name . ' ' . $guest->last_name,
                'national_id'  => $guest->national_id,
                'is_flagged'   => $guest->is_flagged || $match['found'],
                'audit_status' => $guest->audit_status,
                'is_locked'    => ($guest->status === 'blacklisted' || $guest->audit_status === 'audited'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => $enhancedResults
        ]);
    }

    /**
     * حفظ/تحديث بيانات النزيل وتوليد التنبيهات الأمنية
     */
    public function store(StoreGuestRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        try {
            // تمرير رقم السيارة الملتقط من الـ Request للمعالجة الأمنية
            $guest = $this->guestService->storeOrUpdateGuest($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل بيانات النزيل وتدقيقها أمنياً',
                'data'    => $guest
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * عرض ملف نزيل كامل (للمدققين والأمن)
     */
    public function show(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        // جلب تاريخ النزيل بالكامل مع السيارات التي استخدمها
        $guest->load(['reservations.room', 'reservations.branch', 'documents']);

        return response()->json([
            'status' => 'success',
            'data'   => $guest
        ]);
    }

    /**
     * اعتماد النزيل (Audit Approval)
     * دالة خاصة لـ HQ لتحويل الحالة إلى Audited (قفل البيانات)
     */
    public function approve(Guest $guest): JsonResponse
    {
        $this->authorize('audit', $guest);

        $guest->update(['audit_status' => 'audited']);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم اعتماد النزيل، البيانات الآن مقفلة ضد التعديل من الفروع.'
        ]);
    }
}