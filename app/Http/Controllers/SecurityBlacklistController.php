<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlacklistRequest;
use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use App\Services\SecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, Log};
use Exception;

class SecurityBlacklistController extends Controller
{
    public function __construct(protected SecurityService $securityService)
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', SecurityBlacklist::class);

            $perPage = (int)$request->get('per_page', 20);
            if ($perPage < 1) $perPage = 20;
            if ($perPage > 100) $perPage = 100;

            $q = SecurityBlacklist::query()->select([
                'id',
                'identity_hash',
                'risk_level',
                'is_active',
                'reason',
                'instructions',
                'created_by',
                'created_at',
            ]);

            if ($request->filled('risk_level')) {
                $q->where('risk_level', (string)$request->risk_level);
            }

            if ($request->has('is_active')) {
                $q->where('is_active', (bool)$request->boolean('is_active'));
            }

            $list = $q->orderByDesc('id')->simplePaginate($perPage);

            return response()->json([
                'status' => 'success',
                'data'   => $list,
            ]);
        } catch (Exception $e) {
            Log::error("Blacklist Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب القائمة الأمنية'], 500);
        }
    }

    public function store(StoreBlacklistRequest $request): JsonResponse
    {
        $this->authorize('create', SecurityBlacklist::class);

        try {
            $record = $this->securityService->addToBlacklist($request->validated());

            Cache::forget('security:blacklist:active');

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تعميم البيانات أمنياً على جميع الفروع لحظياً',
                'data'    => $record,
            ], 201);

        } catch (Exception $e) {
            Log::warning("Blacklist Store Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'فشل تسجيل القيد الأمني'], 400);
        }
    }

    public function getNotifications(Request $request): JsonResponse
    {
        $this->authorize('audit', SecurityBlacklist::class);

        try {
            $perPage = (int)$request->get('per_page', 15);
            if ($perPage < 1) $perPage = 15;
            if ($perPage > 100) $perPage = 100;

            $onlyUnread = (bool)$request->boolean('unread_only', false);
            $page = max(1, (int)$request->get('page', 1));

            // ✅ versioned cache بدل flush
            $v = (int)Cache::get('sec:notif:v', 1);
            $cacheKey = "sec:notif:v={$v}:pp={$perPage}:p={$page}:unread=" . ($onlyUnread ? '1' : '0');

            $notifications = Cache::remember(
                $cacheKey,
                now()->addSeconds(15),
                function () use ($perPage, $onlyUnread) {
                    $q = SecurityNotification::query()
                        ->select([
                            'id',
                            'blacklist_id',
                            'guest_id',
                            'reservation_id',
                            'branch_name',
                            'receptionist_name',
                            'car_plate_captured',
                            'risk_level',
                            'alert_message',
                            'instructions',
                            'read_at',
                            'read_by',
                            'created_at',
                        ])
                        ->with([
                            'blacklist:id,risk_level',
                            'guest:id,first_name,last_name,national_id',
                            'reservation:id,room_id,branch_id,status',
                            'reservation.room:id,room_number',
                        ])
                        ->orderByDesc('id');

                    if ($onlyUnread) $q->whereNull('read_at');

                    return $q->simplePaginate($perPage);
                }
            );

            return response()->json(['status' => 'success', 'data' => $notifications]);

        } catch (Exception $e) {
            Log::error("Security Radar Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'فشل تحميل رادار التنبيهات'], 500);
        }
    }

    public function markAsRead(int $id): JsonResponse
    {
        $this->authorize('audit', SecurityBlacklist::class);

        try {
            $updated = SecurityNotification::query()
                ->where('id', $id)
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'read_by' => Auth::id(),
                ]);

            if (!$updated) {
                return response()->json(['status' => 'error', 'message' => 'السجل غير موجود أو تمّت قراءته مسبقاً'], 404);
            }

            // ✅ اعمل bump للنسخة بدل flush
            Cache::increment('sec:notif:v');

            return response()->json(['status' => 'success', 'message' => 'تم تأكيد استلام التنبيه وتوثيق الإجراء']);

        } catch (Exception $e) {
            Log::warning("MarkAsRead Error: " . $e->getMessage(), ['id' => $id]);
            return response()->json(['status' => 'error', 'message' => 'السجل غير موجود'], 404);
        }
    }
}