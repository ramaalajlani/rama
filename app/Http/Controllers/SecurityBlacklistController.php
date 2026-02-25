<?php

namespace App\Http\Controllers;

use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use App\Services\SecurityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SecurityBlacklistController extends Controller
{
    protected $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->middleware('auth:sanctum');
        $this->securityService = $securityService;
    }

    /**
     * عرض القائمة السوداء (شاشة الرقابة المركزية)
     * مسموح فقط للنخبة الأمنية في HQ
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SecurityBlacklist::class);

        // جلب البيانات مع إخفاء الهاشات الأصلية لزيادة الأمان
        $list = SecurityBlacklist::latest()->get();

        return response()->json([
            'status' => 'success',
            'count'  => $list->count(),
            'data'   => $list
        ]);
    }

    /**
     * إدراج "هدف أمني" جديد في المنظومة
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SecurityBlacklist::class);

        $validated = $request->validate([
            'national_id'  => 'required|string|min:8',
            'first_name'   => 'required|string',
            'father_name'  => 'required|string',
            'mother_name'  => 'nullable|string',
            'risk_level'   => 'required|in:CRITICAL,WATCHLIST',
            'reason'       => 'nullable|string',
            'instructions' => 'required|string'
        ]);

        // الخدمة ستقوم بتوليد الهاشات الثلاثية (الهوية، الاسم، والأم) صمتاً
        $record = $this->securityService->addToBlacklist($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تعميم البيانات أمنياً على جميع الفروع لحظياً',
            'data'    => $record
        ], 201);
    }

    /**
     * دالة "الرصد الصادم": فحص يدوي لهوية نزيل
     */
    public function check(Request $request): JsonResponse
    {
        $this->authorize('check', SecurityBlacklist::class);

        $request->validate(['national_id' => 'required|string']);

        // نستخدم الـ Hash Identity للفحص الصامت دون تخزين الرقم الصريح في الـ Logs
        $match = $this->securityService->isBlacklisted($request->national_id);

        if ($match) {
            return response()->json([
                'status'         => 'danger',
                'is_blacklisted' => true,
                'message'        => '🛑 تنبيه أمني: الشخص مدرج في قائمة الحظر المركزية',
                'details'        => [
                    'risk_level'   => $match->risk_level,
                    'instructions' => $match->instructions // التعليمات التي أعددتها مسبقاً
                ]
            ], 200);
        }

        return response()->json([
            'status'         => 'success',
            'is_blacklisted' => false,
            'message'        => 'لم يتم العثور على قيود أمنية مسجلة.'
        ]);
    }

    /**
     * سجل التنبيهات اللحظية (الرادار)
     * يظهر هنا من تم رصدهم "فعلياً" داخل الفنادق
     */
    public function getNotifications(): JsonResponse
    {
        if (Gate::denies('audit', SecurityBlacklist::class)) {
            return response()->json(['message' => 'غير مصرح لك بالدخول لغرفة العمليات'], 403);
        }

        $notifications = SecurityNotification::with(['blacklist', 'guest', 'reservation.room'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $notifications
        ]);
    }

    /**
     * معالجة التنبيه (تأكيد القراءة/الإجراء)
     */
    public function markAsRead($id): JsonResponse
    {
        $this->authorize('audit', SecurityBlacklist::class);

        $notification = SecurityNotification::findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تأكيد استلام التنبيه وتوثيق الإجراء'
        ]);
    }

    /**
     * إلغاء الحظر (صلاحية سيادية للـ HQ Admin فقط)
     */
    public function destroy($id): JsonResponse
    {
        $record = SecurityBlacklist::findOrFail($id);
        $this->authorize('delete', $record);
        
        $record->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'تم رفع القيد الأمني عن السجل'
        ]);
    }
}