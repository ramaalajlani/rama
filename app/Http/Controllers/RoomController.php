<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomService;
use App\Http\Requests\StoreRoomRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\{Log, Cache};
use Exception;

class RoomController extends Controller
{
    protected RoomService $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->middleware('auth:sanctum');
        $this->roomService = $roomService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $rooms = $this->roomService->getRoomsForUser($request->all());

            $branchId = $user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor']) && $request->filled('branch_id')
                ? (int)$request->branch_id
                : (int)$user->branch_id;

            $summaryKey = "rooms:summary:b={$branchId}";
            $summary = Cache::remember($summaryKey, now()->addSeconds(20), function () use ($branchId, $user, $request) {
                $q = Room::query()->whereNull('deleted_at');

                if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
                    $q->where('branch_id', $branchId);
                } else {
                    if ($branchId > 0) $q->where('branch_id', $branchId);
                }

                if ($request->filled('floor_number')) $q->where('floor_number', (int)$request->floor_number);
                if ($request->filled('type')) $q->where('type', (string)$request->type);

                return $q->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance
                ")->first();
            });

            return response()->json([
                'status'  => 'success',
                'summary' => [
                    'total'       => (int)($summary->total ?? 0),
                    'occupied'    => (int)($summary->occupied ?? 0),
                    'available'   => (int)($summary->available ?? 0),
                    'maintenance' => (int)($summary->maintenance ?? 0),
                ],
                'data' => $rooms
            ]);
        } catch (Exception $e) {
            Log::error("Room Index Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب بيانات الغرف حالياً.'], 500);
        }
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', Room::class);

            $room = $this->roomService->createRoom($request->validated());

            $b = (int)($room->branch_id ?? 0);
            Cache::forget("rooms:summary:b={$b}");

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل الوحدة السكنية وربطها بالفرع بنجاح',
                'data'    => [
                    'id' => $room->id,
                    'branch_id' => $room->branch_id,
                    'room_number' => $room->room_number,
                    'floor_number' => $room->floor_number,
                    'type' => $room->type,
                    'status' => $room->status,
                    'description' => $room->description,
                    'created_at' => $room->created_at,
                ]
            ], 201);
        } catch (Exception $e) {
            Log::warning("Room Store Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'فشل إنشاء الغرفة.'], 400);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        if (!auth()->user()->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
            return response()->json(['message' => 'صلاحية مرفوضة'], 403);
        }

        $perPage = (int)$request->get('per_page', 20);
        if ($perPage < 1) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $logs = Activity::query()
            ->select([
                'id', 'log_name', 'description', 'subject_type', 'subject_id',
                'causer_type', 'causer_id', 'event', 'created_at'
            ])
            ->whereIn('log_name', ['room_management', 'security_monitor'])
            ->with(['causer' => fn($q) => $q->select('id', 'name')])
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $logs
        ]);
    }

    public function show(Room $room): JsonResponse
    {
        try {
            $this->authorize('view', $room);

            $room->load(['branch:id,name']);

            $activities = $room->activities()
                ->select(['id', 'log_name', 'description', 'event', 'causer_id', 'causer_type', 'created_at'])
                ->with('causer:id,name')
                ->latest('id')
                ->limit(10)
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'room' => $room,
                    'history' => $activities
                ]
            ]);
        } catch (Exception $e) {
            Log::warning("Room Show Error: " . $e->getMessage(), ['room_id' => $room->id]);
            return response()->json(['status' => 'error', 'message' => 'الغرفة غير موجودة.'], 404);
        }
    }

    public function updateStatus(Request $request, Room $room): JsonResponse
    {
        try {
            // ✅ هذا المهم حتى branch_reception يمر ضمن فرعه
            $this->authorize('updateStatus', $room);

            $validated = $request->validate([
                'status' => ['required', 'in:available,occupied,maintenance'],
            ]);

            $updated = $this->roomService->updateStatus($room, $validated['status']);

            Cache::forget("rooms:summary:b=" . (int)$updated->branch_id);

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحديث حالة الغرفة.',
                'data' => $updated
            ]);
        } catch (\Exception $e) {
            Log::warning("Room UpdateStatus Error: " . $e->getMessage(), ['id' => $room->id]);
            return response()->json(['status' => 'error', 'message' => 'فشل تحديث حالة الغرفة.'], 400);
        }
    }
}