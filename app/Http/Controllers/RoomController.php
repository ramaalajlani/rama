<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomService;
use App\Http\Requests\StoreRoomRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

class RoomController extends Controller
{
    protected $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->middleware('auth:sanctum');
        $this->roomService = $roomService;
    }

    /**
     * عرض الغرف (الرادار اللحظي للـ HQ والفرع)
     */
    public function index(Request $request): JsonResponse
    {
        // الخدمة تتعامل مع الفلترة الأمنية (HQ يرى الكل، الفرع يرى نفسه)
        $rooms = $this->roomService->getRoomsForUser($request->all());

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total' => $rooms->count(),
                'occupied' => $rooms->where('status', 'occupied')->count(),
                'available' => $rooms->where('status', 'available')->count(),
                'maintenance' => $rooms->where('status', 'maintenance')->count(),
            ],
            'data' => $rooms
        ]);
    }

    /**
     * إضافة غرفة جديدة للمنظومة
     */
    public function store(StoreRoomRequest $request): JsonResponse
    {
        $this->authorize('create', Room::class);

        $room = $this->roomService->createRoom($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل الوحدة السكنية وربطها بالفرع بنجاح',
            'data'    => $room
        ], 201);
    }

    /**
     * تحديث حالة الغرفة (إجراء أمني حساس)
     */
    public function updateStatus(Request $request, Room $room): JsonResponse
    {
        $this->authorize('update', $room);

        $request->validate([
            'status' => 'required|in:available,maintenance,occupied',
            'reason' => 'nullable|string|max:255' // سبب التغيير للتوثيق الأمني
        ]);

        $this->roomService->updateRoomStatus($room, $request->status, $request->reason);

        return response()->json([
            'status'  => 'success',
            'message' => "تم تحديث حالة الغرفة {$room->room_number} إلى {$request->status}"
        ]);
    }

    /**
     * سجل التحقيقات (Audit Logs): حصري للـ HQ
     */
    public function logs(): JsonResponse
    {
        // التأكد من الصلاحية الإدارية العليا
        if (!auth()->user()->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
            return response()->json(['message' => 'صلاحية مرفوضة: الوصول لسجلات الرقابة محصور للإدارة المركزية'], 403);
        }

        $logs = Activity::whereIn('log_name', ['room_management', 'security_tracking'])
            ->with(['causer', 'subject'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $logs
        ]);
    }

    /**
     * تفاصيل الغرفة مع سجل تاريخي كامل
     */
    public function show(Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        return response()->json([
            'status' => 'success',
            'data'   => $room->load(['branch', 'activities' => function($q) {
                $q->latest()->limit(10);
            }])
        ]);
    }
}