<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Services\{GuestService, SecurityService};
use App\Http\Requests\StoreGuestRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, DB, Log};
use Exception;

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
     * عرض النزلاء مع العزل الأمني بين الفروع
     */
   // في GuestController.php
public function index(Request $request): JsonResponse
{
    $user = $request->user();
    $query = Guest::query();

    // إذا كان موظف فرع، يرى فقط النزلاء الذين زاروا فرعه
    if (!$user->hasRole('hq_admin')) {
        $query->whereHas('reservations', function ($q) use ($user) {
            $q->where('branch_id', $user->branch_id);
        });
    }

    $guests = $query->with(['reservations' => function($q) {
        $q->latest()->limit(1); // عرض آخر حجز فقط لكل نزيل
    }])->paginate(15);

    return response()->json(['status' => 'success', 'data' => $guests]);
}

    /**
     * البحث الذكي مع فحص أمني صامت (Silent Security Match)
     */
    public function search(Request $request): JsonResponse
    {
        $queryText = $request->get('q');

        if (strlen($queryText) < 3) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        try {
            $results = $this->guestService->searchGuests($queryText);

            $enhancedResults = $results->map(function ($guest) {
                // الفحص ضد القائمة السوداء
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
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'فشل البحث الأمني.'], 500);
        }
    }

    /**
     * تسجيل بيانات النزيل
     */
    public function store(StoreGuestRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        try {
            $guest = $this->guestService->storeOrUpdateGuest($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل بيانات النزيل وتدقيقها أمنياً',
                'data'    => $guest->makeHidden(['reservations', 'personalDocuments'])
            ], 201);

        } catch (Exception $e) {
            Log::error("Guest Store Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * عرض ملف نزيل كامل (للمدققين والأمن)
     */
    public function show(Guest $guest): JsonResponse
    {
        try {
            $this->authorize('view', $guest);

            /**
             * نستخدم makeHidden هنا لضمان أن العلاقات المحملة
             * لا تعيد تحميل النزيل نفسه وتسبب الدوران اللانهائي.
             */
            $guest->load([
                'reservations' => function($q) {
                    $q->select('guest_reservations.id', 'room_id', 'branch_id', 'check_in', 'status');
                },
                'reservations.room:id,room_number', 
                'reservations.branch:id,name', 
                'personalDocuments'
            ]);

            return response()->json([
                'status' => 'success',
                'data'   => $guest
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب بيانات النزيل.'], 404);
        }
    }

    /**
     * اعتماد النزيل وقفل البيانات (Audit Approval)
     */
    public function approve(Guest $guest): JsonResponse
    {
        try {
            $this->authorize('audit', $guest);

            $guest->update([
                'audit_status' => 'audited',
                'audited_at' => now(),
                'audited_by' => Auth::id()
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم اعتماد النزيل، البيانات الآن مقفلة ضد التعديل من الفروع.'
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'فشل إجراء الاعتماد.'], 400);
        }
    }
}