<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Services\GuestService;
use App\Http\Requests\StoreGuestRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class GuestController extends Controller
{
    public function __construct(
        protected GuestService $guestService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * قائمة النزلاء + آخر حجز لكل نزيل + عزل الفروع
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        try {
            $user = $request->user();

            $perPage = (int)$request->query('per_page', 15);
            if ($perPage < 1) $perPage = 15;
            if ($perPage > 100) $perPage = 100;

            $status = $request->query('status');
            $audit  = $request->query('audit_status');
            $flaggedOnly = $request->boolean('flagged_only', false);

            $isHQ = $user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor']);

            // لو مو HQ لازم branch_id يكون موجود
            if (!$isHQ && (int)$user->branch_id <= 0) {
                return response()->json(['status' => 'error', 'message' => 'حساب الفرع غير مرتبط بفرع صحيح.'], 403);
            }

            // ✅ آخر pivot row لكل guest بواسطة MAX(id)
            $latestPivotIdSub = DB::table('reservation_guest as rg')
                ->selectRaw('rg.guest_id, MAX(rg.id) as max_id')
                ->groupBy('rg.guest_id');

            $latestReservationIdSub = DB::table('reservation_guest as rg2')
                ->joinSub($latestPivotIdSub, 'mx', function ($join) {
                    $join->on('mx.guest_id', '=', 'rg2.guest_id')
                         ->on('mx.max_id', '=', 'rg2.id');
                })
                ->select('rg2.guest_id', 'rg2.reservation_id');

            $q = Guest::query()->select([
                'guests.id',
                'guests.first_name',
                'guests.father_name',
                'guests.last_name',
                'guests.national_id',
                'guests.phone',
                'guests.audit_status',
                'guests.is_flagged',
                'guests.status',
                'guests.created_at',
            ]);

            // ✅ عزل الفروع
            if (!$isHQ) {
                $branchId = (int)$user->branch_id;

                $q->whereExists(function ($sub) use ($branchId) {
                    $sub->selectRaw('1')
                        ->from('reservation_guest as rg')
                        ->join('guest_reservations as gr', 'gr.id', '=', 'rg.reservation_id')
                        ->whereColumn('rg.guest_id', 'guests.id')
                        ->whereNull('gr.deleted_at')
                        ->where('gr.branch_id', $branchId);
                });
            }

            if (!empty($status)) $q->where('guests.status', (string)$status);
            if (!empty($audit))  $q->where('guests.audit_status', (string)$audit);
            if ($flaggedOnly) $q->where('guests.is_flagged', true);

            // latest reservation per guest
            $q->leftJoinSub($latestReservationIdSub, 'lr', function ($join) {
                $join->on('lr.guest_id', '=', 'guests.id');
            });

            // join reservation (soft delete safe)
            $q->leftJoin('guest_reservations as gr_last', function ($join) {
                $join->on('gr_last.id', '=', 'lr.reservation_id')
                     ->whereNull('gr_last.deleted_at');
            });

            $q->addSelect([
                DB::raw('gr_last.id as latest_reservation_id'),
                DB::raw('gr_last.branch_id as latest_branch_id'),
                DB::raw('gr_last.room_id as latest_room_id'),
                DB::raw('gr_last.check_in as latest_check_in'),
                DB::raw('gr_last.status as latest_reservation_status'),
            ]);

            $q->orderByDesc(DB::raw('gr_last.check_in'))
              ->orderByDesc('guests.id');

            $guests = $q->simplePaginate($perPage);

            return response()->json([
                'status' => 'success',
                'data'   => $guests,
            ]);
        } catch (Exception $e) {
            Log::error("Guest Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ أثناء جلب البيانات'], 500);
        }
    }

    /**
     * بحث سريع + عزل فروع
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        $queryText = trim((string)$request->get('q', ''));

        if (mb_strlen($queryText) < 3) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        try {
            $user = $request->user();
            $isHQ = $user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor']);

            if (!$isHQ && (int)$user->branch_id <= 0) {
                return response()->json(['status' => 'error', 'message' => 'حساب الفرع غير مرتبط بفرع صحيح.'], 403);
            }

            // نستخدم service للبحث
            $results = $this->guestService->searchGuests($queryText);

            // ✅ عزل الفروع حتى في نتائج البحث
            if (!$isHQ) {
                $branchId = (int)$user->branch_id;

                $allowedIds = DB::table('reservation_guest as rg')
                    ->join('guest_reservations as gr', 'gr.id', '=', 'rg.reservation_id')
                    ->where('gr.branch_id', $branchId)
                    ->whereNull('gr.deleted_at')
                    ->whereIn('rg.guest_id', $results->pluck('id')->all())
                    ->distinct()
                    ->pluck('rg.guest_id')
                    ->all();

                $results = $results->whereIn('id', $allowedIds)->values();
            }

            $enhancedResults = $results->map(function ($guest) {
                return [
                    'id'            => $guest->id,
                    'full_name'     => trim(($guest->first_name ?? '') . ' ' . ($guest->father_name ?? '') . ' ' . ($guest->last_name ?? '')),
                    'national_id'   => $guest->national_id,
                    'is_flagged'    => (bool)($guest->is_flagged ?? false),
                    'audit_status'  => $guest->audit_status,
                    'status'        => $guest->status,
                    'is_restricted' => (($guest->status ?? '') === 'blacklisted') || (($guest->audit_status ?? '') === 'audited'),
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => $enhancedResults,
            ]);
        } catch (Exception $e) {
            Log::error("Guest Search Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'فشل البحث.'], 500);
        }
    }

    public function store(StoreGuestRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        try {
            $guest = $this->guestService->storeOrUpdateGuest($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل بيانات النزيل.',
                'data'    => [
                    'id'          => $guest->id,
                    'first_name'  => $guest->first_name,
                    'father_name' => $guest->father_name,
                    'last_name'   => $guest->last_name,
                    'national_id' => $guest->national_id,
                    'phone'       => $guest->phone,
                    'audit_status'=> $guest->audit_status,
                    'is_flagged'  => (bool)$guest->is_flagged,
                    'status'      => $guest->status,
                    'created_at'  => $guest->created_at,
                ],
            ], 201);
        } catch (Exception $e) {
            Log::error("Guest Store Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        }
    }

    public function show(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        try {
            $guest->load([
                'reservations' => function ($q) {
                    $q->select('guest_reservations.id', 'guest_reservations.room_id', 'guest_reservations.branch_id', 'guest_reservations.check_in', 'guest_reservations.status')
                      ->orderByDesc('guest_reservations.check_in');
                },
                'reservations.room:id,room_number,floor_number',
                'reservations.branch:id,name',
                'personalDocuments:id,guest_id,reservation_id,document_type,file_name,mime_type,file_size,created_at',
                'auditor:id,name',
            ]);

            $guest->makeHidden(['national_id_hash', 'full_security_hash']);

            return response()->json([
                'status' => 'success',
                'data'   => $guest,
            ]);
        } catch (Exception $e) {
            Log::warning("Guest Show Error: " . $e->getMessage(), ['guest_id' => $guest->id]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب بيانات النزيل.'], 404);
        }
    }

    public function approve(Guest $guest): JsonResponse
    {
        $this->authorize('audit', $guest);

        try {
            // لو مدقق مسبقاً ما نعيد
            if (($guest->audit_status ?? '') === 'audited') {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'النزيل مدقق مسبقاً.',
                ]);
            }

            $guest->update([
                'audit_status' => 'audited',
                'audited_at'   => now(),
                'audited_by'   => Auth::id(),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم اعتماد النزيل، البيانات الآن مقفلة ضد التعديل من الفروع.',
            ]);
        } catch (Exception $e) {
            Log::warning("Guest Approve Error: " . $e->getMessage(), ['guest_id' => $guest->id]);
            return response()->json(['status' => 'error', 'message' => 'فشل إجراء الاعتماد.'], 400);
        }
    }
}