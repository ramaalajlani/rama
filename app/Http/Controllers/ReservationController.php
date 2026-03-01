<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Models\{Reservation, GuestDocument};
use App\Services\ReservationService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Auth, Cache, Log, Storage, DB};
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use Exception;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservationService)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/reservations
     * Filters:
     * - per_page, page
     * - audit_status: all|new|audited|flagged
     * - status: pending|confirmed|checked_out|cancelled
     * - branch_id (HQ only)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $perPage = max(1, min(100, (int)$request->get('per_page', 15)));
            $page    = max(1, (int)$request->get('page', 1));

            $auditStatus = (string)$request->get('audit_status', 'all');
            $status      = (string)$request->get('status', '');

            // branchId: HQ roles can pass branch_id; otherwise forced to user's branch_id
            $branchId = $user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor'])
                ? (int)($request->filled('branch_id') ? $request->branch_id : 0)
                : (int)$user->branch_id;

            // Cache versioning
            $v = (int)Cache::get('reservations:cache:v', 1);
            $cacheKey = "res:index:v={$v}:u={$user->id}:b={$branchId}:p={$page}:pp={$perPage}:audit={$auditStatus}:st={$status}";

            $data = Cache::remember(
                $cacheKey,
                now()->addSeconds(60),
                function () use ($request, $user, $auditStatus, $branchId, $perPage) {
                    return $this->reservationService->paginateReservations(
                        $request,
                        $user,
                        $auditStatus,
                        $branchId,
                        $perPage
                    );
                }
            );

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Reservation Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ أثناء جلب البيانات'], 500);
        }
    }

    /**
     * POST /api/reservations
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', Reservation::class);

            $reservation = $this->reservationService->createReservationWithOccupants($request);
            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم إنشاء الإقامة بنجاح.',
                'data'    => $reservation,
            ], 201);
        } catch (Exception $e) {
            Log::error("Store Reservation Failure: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/reservations/{reservation}
     */
    public function show(Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('view', $reservation);

            $reservation->loadMissing([
                'room:id,room_number,floor_number,type,branch_id',
                'branch:id,name',
                'creator:id,name',
                'occupants:id,first_name,father_name,last_name,mother_name,national_id,phone',
                'documents:id,reservation_id,guest_id,document_type,file_name,mime_type,file_size,created_at',
            ]);

            return response()->json([
                'status' => 'success',
                'data'   => $reservation
            ]);
        } catch (Exception $e) {
            Log::warning("Reservation Show Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب بيانات الإقامة'], 404);
        }
    }

    /**
     * PATCH/PUT /api/reservations/{reservation}
     */
    public function update(UpdateReservationRequest $request, Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('update', $reservation);

            $updated = $this->reservationService->updateReservation($reservation, $request->validated());
            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الإقامة بنجاح.',
                'data'    => $updated,
            ]);
        } catch (Exception $e) {
            Log::warning("Reservation Update Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/reservations/{reservation}
     * ✅ ممنوع
     */
    public function destroy(Reservation $reservation): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'الحذف غير مسموح ضمن هذا النظام.'
        ], 403);
    }

    /**
     * POST /api/reservations/{reservation}/audit
     */
    public function audit(Request $request, Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('audit', $reservation);

            $notes  = (string)$request->input('audit_notes', '');
            $result = $this->reservationService->auditAndLock($reservation, $notes);

            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم التدقيق وقفل السجل.',
                'data'    => $result,
            ]);
        } catch (Exception $e) {
            Log::warning("Reservation Audit Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/reservations/{reservation}/checkout
     */
    public function checkOut(Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('checkOut', $reservation);

            $this->reservationService->checkOut($reservation);
            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل الخروج بنجاح وتفريغ الغرفة.',
            ]);
        } catch (Exception $e) {
            Log::warning("Reservation Checkout Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/reservations/active
     * ✅ الإقامات النشطة الآن (actual_check_out = null)
     * ⚡ ملاحظة أداء: GlobalScope بالـ Model يعزل حسب الفرع تلقائياً لغير HQ
     */
    public function activeOccupancy(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $q = Reservation::query()
                ->select([
                    'guest_reservations.id',
                    'guest_reservations.branch_id',
                    'guest_reservations.room_id',
                    'guest_reservations.check_in',
                    'guest_reservations.check_out',
                    'guest_reservations.actual_check_out',
                    'guest_reservations.status',
                    'guest_reservations.audit_status',
                    'guest_reservations.is_locked',
                    'guest_reservations.vehicle_plate',
                    'guest_reservations.created_at',
                ])
                ->whereNull('guest_reservations.actual_check_out')
                ->with([
                    'room:id,room_number,floor_number',
                    'occupants:id,first_name,father_name,last_name,mother_name',
                    'documents:id,reservation_id,guest_id,document_type',
                ])
                ->orderByDesc('guest_reservations.check_in');

            // HQ can optionally filter by branch_id
            if ($user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor']) && $request->filled('branch_id')) {
                $q->where('guest_reservations.branch_id', (int)$request->branch_id);
            }

            $perPage = max(1, min(100, (int)$request->get('per_page', 15)));
            $data = $q->simplePaginate($perPage);

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Active Occupancy Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب الإشغال الحالي'], 500);
        }
    }

    /**
     * GET /api/reservations/stats/daily
     */
    public function dailyStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $todayStart = Carbon::today();
            $todayEnd   = Carbon::tomorrow();

            $qBase = Reservation::query();

            // HQ optional filter by branch_id; otherwise GlobalScope already filters for branch users
            if ($user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor']) && $request->filled('branch_id')) {
                $qBase->where('branch_id', (int)$request->branch_id);
            }

            $createdToday   = (clone $qBase)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
            $checkInsToday  = (clone $qBase)->whereBetween('check_in', [$todayStart, $todayEnd])->count();
            $checkOutsToday = (clone $qBase)->whereBetween('actual_check_out', [$todayStart, $todayEnd])->count();
            $activeNow      = (clone $qBase)->whereNull('actual_check_out')->count();
            $lockedNow      = (clone $qBase)->whereNull('actual_check_out')->where('is_locked', true)->count();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'created_today'   => $createdToday,
                    'checkins_today'  => $checkInsToday,
                    'checkouts_today' => $checkOutsToday,
                    'active_now'      => $activeNow,
                    'locked_now'      => $lockedNow,
                    'date'            => $todayStart->toDateString(),
                ]
            ]);
        } catch (Exception $e) {
            Log::error("Daily Stats Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'فشل تحميل الإحصائيات اليومية'], 500);
        }
    }

    /**
     * PATCH /api/reservations/{reservation}/toggle-lock
     */
    public function toggleLock(Request $request, Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('update', $reservation);

            $newState = !$reservation->is_locked;

            $reservation->update([
                'is_locked' => $newState,
                'locked_by' => $newState ? Auth::id() : null,
            ]);

            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success',
                'message' => $newState ? 'تم قفل السجل.' : 'تم فتح قفل السجل.',
                'data'    => $reservation->fresh(),
            ]);
        } catch (Exception $e) {
            Log::warning("Toggle Lock Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/reservations/{reservation}/documents
     */
    public function viewDocuments(Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('viewDocuments', $reservation);

            $docs = GuestDocument::query()
                ->select([
                    'id',
                    'reservation_id',
                    'guest_id',
                    'document_type',
                    'file_name',
                    'mime_type',
                    'file_size',
                    'created_at',
                ])
                ->where('reservation_id', (int)$reservation->id)
                ->orderByDesc('id')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $docs,
            ]);
        } catch (Exception $e) {
            Log::warning("View Documents Error: " . $e->getMessage(), ['id' => $reservation->id ?? null]);
            return response()->json(['status' => 'error', 'message' => 'غير مصرح لك أو لا توجد وثائق'], 403);
        }
    }

    /**
     * GET /api/reservations/documents/{document}/view
     * ✅ تحقق فرع + منع الاستقبال إذا السجل مقفل + pivot verification
     */
    public function showDocument(GuestDocument $document): StreamedResponse
    {
        $user = Auth::user();

        $reservation = Reservation::query()
            ->select(['id', 'branch_id', 'is_locked'])
            ->where('id', (int)$document->reservation_id)
            ->firstOrFail();

        $hqRoles = ['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor'];
        $isHQ = $user->hasAnyRole($hqRoles);

        if (!$isHQ) {
            if (!$user->hasRole('branch_reception')) {
                abort(403, "غير مصرح لك");
            }

            if ((int)$user->branch_id !== (int)$reservation->branch_id) {
                abort(403, "غير مصرح لك بالوصول لهذه الوثيقة");
            }

            if ((bool)$reservation->is_locked) {
                abort(403, "السجل مقفل ولا يمكن عرض الوثائق");
            }

            if (!empty($document->guest_id)) {
                $ok = DB::table('reservation_guest')
                    ->where('reservation_id', (int)$reservation->id)
                    ->where('guest_id', (int)$document->guest_id)
                    ->exists();

                if (!$ok) {
                    abort(403, "هذه الوثيقة لا تتبع لهذه الإقامة");
                }
            }
        }

        if (!Storage::disk('private')->exists($document->file_path)) {
            abort(404, "الملف غير موجود");
        }

        return Storage::disk('private')->response(
            $document->file_path,
            $document->file_name ?? null,
            $document->mime_type ? ['Content-Type' => $document->mime_type] : []
        );
    }

    /**
     * ✅ HELPER: date range fast (index-friendly)
     * ?date=YYYY-MM-DD
     */
    private function dayRange(Request $request): array
    {
        $dateStr = (string)$request->get('date', now()->toDateString());
        $start = Carbon::parse($dateStr)->startOfDay();
        $end   = $start->copy()->addDay();
        return [$start, $end];
    }

    /**
     * GET /api/reservations/today-checkins?date=YYYY-MM-DD
     * ✅ دخول يوم محدد (Eloquent)
     */
    public function todayCheckins(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $date = (string)$request->get('date', now()->toDateString());

            $q = Reservation::query()
                ->select([
                    'guest_reservations.id',
                    'guest_reservations.branch_id',
                    'guest_reservations.room_id',
                    'guest_reservations.check_in',
                    'guest_reservations.check_out',
                    'guest_reservations.actual_check_out',
                    'guest_reservations.status',
                    'guest_reservations.audit_status',
                    'guest_reservations.is_locked',
                    'guest_reservations.vehicle_plate',
                    'guest_reservations.created_at',
                ])
                ->whereDate('guest_reservations.check_in', $date)
                ->with([
                    'room:id,room_number,floor_number',
                    'occupants:id,first_name,father_name,last_name,mother_name',
                ])
                ->orderByDesc('guest_reservations.check_in');

            if ($user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor']) && $request->filled('branch_id')) {
                $q->where('guest_reservations.branch_id', (int)$request->branch_id);
            }

            $data = $q->get();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Today Checkins Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب دخول اليوم'], 500);
        }
    }

    /**
     * GET /api/reservations/today-checkouts?date=YYYY-MM-DD
     * ✅ غادروا يوم محدد (Eloquent)
     */
    public function todayCheckouts(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $date = (string)$request->get('date', now()->toDateString());

            $q = Reservation::query()
                ->select([
                    'guest_reservations.id',
                    'guest_reservations.branch_id',
                    'guest_reservations.room_id',
                    'guest_reservations.check_in',
                    'guest_reservations.check_out',
                    'guest_reservations.actual_check_out',
                    'guest_reservations.status',
                    'guest_reservations.audit_status',
                    'guest_reservations.is_locked',
                    'guest_reservations.vehicle_plate',
                    'guest_reservations.created_at',
                ])
                ->whereDate('guest_reservations.actual_check_out', $date)
                ->with([
                    'room:id,room_number,floor_number',
                    'occupants:id,first_name,father_name,last_name,mother_name',
                ])
                ->orderByDesc('guest_reservations.actual_check_out');

            if ($user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor']) && $request->filled('branch_id')) {
                $q->where('guest_reservations.branch_id', (int)$request->branch_id);
            }

            $data = $q->get();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Today Checkouts Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب مغادرات اليوم'], 500);
        }
    }

    /**
     * GET /api/reservations/due-checkouts-today?date=YYYY-MM-DD&limit=200
     * ✅ لازم يغادروا (Eloquent)
     */
    public function dueCheckoutsToday(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $date = (string)$request->get('date', now()->toDateString());

            $q = Reservation::query()
                ->select([
                    'guest_reservations.id',
                    'guest_reservations.branch_id',
                    'guest_reservations.room_id',
                    'guest_reservations.check_in',
                    'guest_reservations.check_out',
                    'guest_reservations.actual_check_out',
                    'guest_reservations.status',
                    'guest_reservations.audit_status',
                    'guest_reservations.is_locked',
                    'guest_reservations.vehicle_plate',
                    'guest_reservations.created_at',
                ])
                ->whereNotNull('guest_reservations.check_out')
                ->whereDate('guest_reservations.check_out', $date)
                ->whereNull('guest_reservations.actual_check_out')
                ->whereIn('guest_reservations.status', ['confirmed', 'pending'])
                ->with([
                    'room:id,room_number,floor_number',
                    'occupants:id,first_name,father_name,last_name,mother_name',
                ])
                ->orderBy('guest_reservations.check_out', 'asc');

            if ($user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor']) && $request->filled('branch_id')) {
                $q->where('guest_reservations.branch_id', (int)$request->branch_id);
            }

            $limit = max(1, min(300, (int)$request->get('limit', 200)));
            $data = $q->limit($limit)->get();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Due Checkouts Today Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب مغادرات اليوم المطلوبة'], 500);
        }
    }

    /**
     * ✅ LITE: GET /api/reservations/today-checkins-lite?date=YYYY-MM-DD&limit=200
     * ⚡ أسرع: بدون Eloquent relations الثقيلة + primary guest فقط
     */
    public function todayCheckinsLite(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            [$start, $end] = $this->dayRange($request);
            $limit = max(1, min(300, (int)$request->get('limit', 200)));

            $q = DB::table('guest_reservations as r')
                ->leftJoin('rooms as rm', 'rm.id', '=', 'r.room_id')
                ->leftJoin('reservation_guest as rg', function ($join) {
                    $join->on('rg.reservation_id', '=', 'r.id')
                        ->where('rg.participant_type', '=', 'primary');
                })
                ->leftJoin('guests as g', 'g.id', '=', 'rg.guest_id')
                ->select([
                    'r.id',
                    'r.branch_id',
                    'r.room_id',
                    'rm.room_number',
                    'rm.floor_number',
                    'r.check_in',
                    'r.check_out',
                    'r.actual_check_out',
                    'r.status',
                    'r.audit_status',
                    'r.is_locked',
                    'r.vehicle_plate',
                    'r.created_at',
                    DB::raw("TRIM(CONCAT_WS(' ', g.first_name, g.father_name, g.last_name)) as primary_guest_name"),
                ])
                ->whereNull('r.deleted_at')
                ->where('r.check_in', '>=', $start)
                ->where('r.check_in', '<', $end)
                ->orderByDesc('r.check_in')
                ->limit($limit);

            if (!$user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor'])) {
                $q->where('r.branch_id', (int)$user->branch_id);
            } elseif ($request->filled('branch_id')) {
                $q->where('r.branch_id', (int)$request->branch_id);
            }

            $data = $q->get();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Today Checkins Lite Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب دخول اليوم (Lite)'], 500);
        }
    }

    /**
     * ✅ LITE: GET /api/reservations/today-checkouts-lite?date=YYYY-MM-DD&limit=200
     * ⚡ أسرع: غادروا اليوم (actual_check_out ضمن اليوم)
     */
    public function todayCheckoutsLite(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            [$start, $end] = $this->dayRange($request);
            $limit = max(1, min(300, (int)$request->get('limit', 200)));

            $q = DB::table('guest_reservations as r')
                ->leftJoin('rooms as rm', 'rm.id', '=', 'r.room_id')
                ->leftJoin('reservation_guest as rg', function ($join) {
                    $join->on('rg.reservation_id', '=', 'r.id')
                        ->where('rg.participant_type', '=', 'primary');
                })
                ->leftJoin('guests as g', 'g.id', '=', 'rg.guest_id')
                ->select([
                    'r.id',
                    'r.branch_id',
                    'r.room_id',
                    'rm.room_number',
                    'rm.floor_number',
                    'r.check_in',
                    'r.check_out',
                    'r.actual_check_out',
                    'r.status',
                    'r.audit_status',
                    'r.is_locked',
                    'r.vehicle_plate',
                    'r.created_at',
                    DB::raw("TRIM(CONCAT_WS(' ', g.first_name, g.father_name, g.last_name)) as primary_guest_name"),
                ])
                ->whereNull('r.deleted_at')
                ->whereNotNull('r.actual_check_out')
                ->where('r.actual_check_out', '>=', $start)
                ->where('r.actual_check_out', '<', $end)
                ->orderByDesc('r.actual_check_out')
                ->limit($limit);

            if (!$user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor'])) {
                $q->where('r.branch_id', (int)$user->branch_id);
            } elseif ($request->filled('branch_id')) {
                $q->where('r.branch_id', (int)$request->branch_id);
            }

            $data = $q->get();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (Exception $e) {
            Log::error("Today Checkouts Lite Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب مغادرات اليوم (Lite)'], 500);
        }
    }

    /**
     * ✅ LITE: GET /api/reservations/due-checkouts-today-lite?date=YYYY-MM-DD&limit=200
     * ⚡ أسرع: لازم يغادروا اليوم (check_out ضمن اليوم + actual_check_out null)
     */
    public function dueCheckoutsTodayLite(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $this->authorize('viewAny', Reservation::class);

            $date = (string)$request->get('date', now()->toDateString());
            $limit = max(1, min(300, (int)$request->get('limit', 200)));

            $q = DB::table('guest_reservations as r')
                ->leftJoin('rooms as rm', 'rm.id', '=', 'r.room_id')
                ->leftJoin('reservation_guest as rg', function ($join) {
                    $join->on('rg.reservation_id', '=', 'r.id')
                         ->where('rg.participant_type', '=', 'primary');
                })
                ->leftJoin('guests as g', 'g.id', '=', 'rg.guest_id')
                ->select([
                    'r.id','r.branch_id','r.room_id',
                    'rm.room_number','rm.floor_number',
                    'r.check_in','r.check_out','r.actual_check_out',
                    'r.status','r.audit_status','r.is_locked',
                    'r.vehicle_plate','r.created_at',
                    DB::raw("TRIM(CONCAT_WS(' ', g.first_name, g.father_name, g.last_name)) as primary_guest_name"),
                ])
                ->whereNull('r.deleted_at')
                ->whereNotNull('r.check_out')
                ->whereDate('r.check_out', $date)
                ->whereNull('r.actual_check_out')
                ->whereIn('r.status', ['confirmed','pending'])
                ->orderBy('r.check_out', 'asc')
                ->limit($limit);

            if (!$user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor'])) {
                $q->where('r.branch_id', (int)$user->branch_id);
            } elseif ($request->filled('branch_id')) {
                $q->where('r.branch_id', (int)$request->branch_id);
            }

            return response()->json(['status' => 'success', 'data' => $q->get()]);
        } catch (Exception $e) {
            Log::error("Due Checkouts Today Lite Error: ".$e->getMessage(), ['trace'=>$e->getTraceAsString()]);
            return response()->json(['status'=>'error','message'=>'تعذر جلب مغادرات اليوم المطلوبة (Lite)'], 500);
        }
    }

    private function clearReservationCache(): void
    {
        Cache::increment('reservations:cache:v');
    }
}