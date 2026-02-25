<?php

namespace App\Http\Controllers;

use App\Models\{Reservation, Room, GuestDocument};
use App\Services\{ReservationService, GuestService};
use App\Http\Requests\{StoreReservationRequest};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Auth, Cache, DB, Log};
use Carbon\Carbon;
use Exception;

class ReservationController extends Controller
{
    protected $reservationService;
    protected $guestService;

    public function __construct(ReservationService $reservationService, GuestService $guestService)
    {
        // تأمين كافة العمليات داخل المتحكم عبر Sanctum
        $this->middleware('auth:sanctum');
        $this->reservationService = $reservationService;
        $this->guestService = $guestService;
    }

    /**
     * حساب إحصائيات الدخول والخروج لليوم الحالي (Dashboard)
     * تم التعديل لضمان جلب الإحصائيات اللحظية (In-house) بدقة
     */
    public function dailyStats(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // تحديد الفرع: الإدارة ترى إحصائيات أي فرع تطلبه، الموظف يرى فرعه فقط
        $branchId = ($user->hasRole('hq_admin') && $request->filled('branch_id')) 
                    ? $request->branch_id 
                    : $user->branch_id;

        $stats = $this->reservationService->getDailyStats($branchId);

        return response()->json([
            'status' => 'success',
            'date'   => Carbon::today()->toDateString(),
            'data'   => $stats
        ]);
    }

    /**
     * عرض قائمة الحجوزات مع دعم البحث والفلترة وفصل الفروع
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $page = $request->get('page', 1);
            $status = $request->get('audit_status', 'all');
            
            // مفتاح كاش فريد لكل مستخدم وصفحة لضمان سرعة الاستجابة
            $cacheKey = "res_u{$user->id}_p{$page}_s{$status}";

            $reservations = Cache::remember($cacheKey, 300, function () use ($request, $user) {
                $query = Reservation::query();

                // عزل الفروع: الموظف يرى بيانات فرعه فقط، الإدارة ترى الكل
                if (!$user->hasRole('hq_admin')) {
                    $query->where('branch_id', $user->branch_id);
                }

                // تحميل البيانات المرتبطة (Eager Loading)
                $query->with([
                    'occupants' => fn($q) => $q->select('guests.*'),
                    'room:id,room_number', 
                    'branch:id,name',
                    'creator:id,name'
                ]);

                // فلترة بناءً على حالة التدقيق الأمني
                if ($request->filled('audit_status') && $request->audit_status !== 'all') {
                    $query->where('audit_status', $request->audit_status);
                }

                return $query->latest()->paginate(15);
            });

            return response()->json([
                'status' => 'success', 
                'data'   => $reservations
            ]);

        } catch (Exception $e) {
            Log::error("Reservation Index Error: " . $e->getMessage());
            return response()->json([
                'status'  => 'error', 
                'message' => 'حدث خطأ أثناء جلب البيانات'
            ], 500);
        }
    }

    /**
     * تسجيل إقامة جديدة (Check-in) مع ربط النزلاء ووثائقهم
     * تم دمج تحسينات لوحة السيارة ومعالجة الوثائق
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            try {
                // 1. جلب البيانات الموثقة (المعدلة في الـ Request لتوحيد vehicle_plate)
                $data = $request->validated();
                
                // التأكد من تمرير القيمة الصحيحة للوحة السيارة للخدمة
                $data['vehicle_plate'] = $request->vehicle_plate;

                // 2. إنشاء سجل الحجز الأساسي عبر الخدمة
                $reservation = $this->reservationService->storeReservation($data);

                // 3. معالجة بيانات النزلاء (الأساسي والمرافقين)
                if ($request->has('occupants')) {
                    foreach ($request->occupants as $index => $occupantData) {
                        // إنشاء أو تحديث بيانات النزيل
                        $guest = $this->guestService->storeOrUpdateGuest($occupantData);

                        // ربط النزيل بالحجز في الجدول الوسيط
                        $reservation->occupants()->syncWithoutDetaching([
                            $guest->id => [
                                'participant_type'         => ($occupantData['is_primary'] ?? false) ? 'primary' : 'companion',
                                'vehicle_plate_at_checkin' => $reservation->vehicle_plate,
                                'registered_by'            => Auth::id(),
                            ]
                        ]);

                        // 4. رفع وحفظ وثائق الهوية (تستخدم الآن hash_file و mime_type في الخدمة)
                        if ($request->hasFile("occupants.$index.id_image")) {
                            $this->reservationService->storeGuestDocument(
                                $guest->id, 
                                $reservation->id, 
                                $request->file("occupants.$index.id_image")
                            );
                        }
                    }
                }

                // تنظيف الكاش لتحديث الإحصائيات (الرقم 2) والنتائج فوراً
                $this->clearReservationCache();

                return response()->json([
                    'status'  => 'success', 
                    'message' => 'تم تسجيل الدخول بنجاح وتحديث حالة الغرفة.',
                    'data'    => $reservation->load(['occupants', 'room'])
                ], 201);

            } catch (Exception $e) {
                DB::rollBack();
                Log::error("Store Reservation Failure: " . $e->getMessage());
                return response()->json([
                    'status'  => 'error', 
                    'message' => $e->getMessage()
                ], 400);
            }
        });
    }

    /**
     * تسجيل خروج (Check-out) وتفريغ الغرفة
     */
    public function checkOut(Reservation $reservation): JsonResponse
    {
        try {
            // التحقق من الصلاحية: هل الحجز يتبع لفرع الموظف؟
            if (Auth::user()->branch_id !== $reservation->branch_id && !Auth::user()->hasRole('hq_admin')) {
                return response()->json([
                    'status'  => 'error', 
                    'message' => 'غير مصرح لك بإتمام عملية الخروج لهذا الفرع'
                ], 403);
            }

            // تنفيذ عملية الخروج عبر الخدمة (تحديث actual_check_out)
            $this->reservationService->checkOut($reservation);
            
            // تنظيف الكاش لتحديث الإحصائيات اليومية فوراً
            $this->clearReservationCache();

            return response()->json([
                'status'  => 'success', 
                'message' => 'تم تسجيل الخروج بنجاح وتغيير حالة الغرفة إلى متاحة.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error', 
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * وظيفة مساعدة لتطهير الكاش
     */
    protected function clearReservationCache()
    {
        // مسح الكاش يضمن ظهور البيانات الجديدة في الـ Index والـ DailyStats
        Cache::flush(); 
    }
}