<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomService;
use App\Http\Requests\StoreRoomRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Log;
use Exception;

class RoomController extends Controller
{
    protected $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->middleware('auth:sanctum');
        $this->roomService = $roomService;
    }

    /**
     * عرض الغرف: تحسين الأداء ومنع استهلاك الذاكرة
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // نستخدم paginate بدلاً من جلب الكل لتحسين أداء XAMPP
            $rooms = $this->roomService->getRoomsForUser($request->all());

            /**
             * ملاحظة أمنية: تم نقل الحسابات الإحصائية لتكون أكثر دقة 
             * يفضل أن تأتي هذه الأرقام من استعلام منفصل أو من خلال الـ Service 
             * لضمان عدم تحميل موديلات الغرف بالكامل في الذاكرة.
             */
            return response()->json([
                'status' => 'success',
                'summary' => [
                    'total'       => $rooms->total(), // استخدام أرقام الترقيم التلقائي
                    'occupied'    => Room::where('status', 'occupied')->count(), 
                    'available'   => Room::where('status', 'available')->count(),
                    'maintenance' => Room::where('status', 'maintenance')->count(),
                ],
                'data' => $rooms
            ]);
        } catch (Exception $e) {
            Log::error("Room Index Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب بيانات الغرف حالياً.'], 500);
        }
    }

    /**
     * إضافة غرفة جديدة
     */
    public function store(StoreRoomRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', Room::class);
            $room = $this->roomService->createRoom($request->validated());

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تسجيل الوحدة السكنية وربطها بالفرع بنجاح',
                'data'    => $room->makeHidden(['branch', 'activities']) // منع الدوران عند الرد
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'فشل إنشاء الغرفة.'], 400);
        }
    }

    /**
     * سجل التحقيقات (Audit Logs): حصري للـ HQ
     */
    public function logs(): JsonResponse
    {
        if (!auth()->user()->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
            return response()->json(['message' => 'صلاحية مرفوضة'], 403);
        }

        /**
         * تحسين: نحدد الحقول المطلوبة من الـ causer (الموظف) 
         * لتجنب جلب بياناته الحساسة مثل الباسورد أو التوكنات داخل السجل
         */
        $logs = Activity::whereIn('log_name', ['room_management', 'security_monitor'])
            ->with([
                'causer' => fn($q) => $q->select('id', 'name'),
                'subject'
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $logs
        ]);
    }

    /**
     * تفاصيل الغرفة
     */
    public function show(Room $room): JsonResponse
    {
        try {
            $this->authorize('view', $room);

            // تحميل العلاقات بشكل محدود جداً
            $room->load(['branch:id,name']);
            
            // تحميل النشاطات مع تحديد الموظف الذي قام بالفعل فقط
            $activities = $room->activities()->with('causer:id,name')->latest()->limit(10)->get();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'room' => $room,
                    'history' => $activities
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'الغرفة غير موجودة.'], 404);
        }
    }
}