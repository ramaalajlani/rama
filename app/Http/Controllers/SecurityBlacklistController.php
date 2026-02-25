<?php

namespace App\Http\Controllers;

use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use App\Services\SecurityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Gate, Log, Auth};
use Exception;

class SecurityBlacklistController extends Controller
{
    protected $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->middleware('auth:sanctum');
        $this->securityService = $securityService;
    }

    /**
     * عرض القائمة السوداء
     * تم إضافة Pagination لضمان سرعة الاستجابة في XAMPP
     */
    public function index(): JsonResponse
    {
        try {
            $this->authorize('viewAny', SecurityBlacklist::class);

            // نستخدم الترقيم (Paginate) لأن القائمة قد تكبر مع الوقت
            $list = SecurityBlacklist::latest()->paginate(20);

            return response()->json([
                'status' => 'success',
                'data'   => $list
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'تعذر جلب القائمة الأمنية'], 500);
        }
    }

    /**
     * إدراج هدف أمني جديد
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SecurityBlacklist::class);

        $validated = $request->validate([
            'national_id'  => 'required|string|min:8',
            'first_name'   => 'required|string',
            'father_name'  => 'required|string',
            'mother_name'  => 'nullable|string',
            'risk_level'   => 'required|in:CRITICAL,WATCHLIST,DANGER,BANNED', // تحديث لتطابق الموديل
            'reason'       => 'nullable|string',
            'instructions' => 'required|string'
        ]);

        try {
            $record = $this->securityService->addToBlacklist($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تعميم البيانات أمنياً على جميع الفروع لحظياً',
                'data'    => $record
            ], 201);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'فشل تسجيل القيد الأمني'], 400);
        }
    }

    /**
     * سجل التنبيهات اللحظية (الرادار)
     * تحسين تحميل العلاقات لكسر حلقة الدوران
     */
    public function getNotifications(): JsonResponse
    {
        if (Gate::denies('audit', SecurityBlacklist::class)) {
            return response()->json(['message' => 'غير مصرح لك بالدخول لغرفة العمليات'], 403);
        }

        try {
            // تحميل العلاقات مع تحديد الحقول المطلوبة فقط
            $notifications = SecurityNotification::with([
                'blacklist:id,risk_level', 
                'guest:id,first_name,last_name,national_id', 
                'reservation' => function($q) {
                    $q->select('id', 'room_id', 'branch_id', 'status');
                },
                'reservation.room:id,room_number'
            ])
            ->latest()
            ->paginate(15);

            return response()->json([
                'status' => 'success',
                'data'   => $notifications
            ]);
        } catch (Exception $e) {
            Log::error("Security Radar Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'فشل تحميل رادار التنبيهات'], 500);
        }
    }

    /**
     * معالجة التنبيه (تأكيد القراءة)
     */
    public function markAsRead($id): JsonResponse
    {
        try {
            $this->authorize('audit', SecurityBlacklist::class);

            $notification = SecurityNotification::findOrFail($id);
            $notification->update([
                'read_at' => now(),
                'processed_by' => Auth::id() // توثيق من قام بالاستلام
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تأكيد استلام التنبيه وتوثيق الإجراء'
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'السجل غير موجود'], 404);
        }
    }
}