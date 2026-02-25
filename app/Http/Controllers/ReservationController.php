<?php

namespace App\Http\Controllers;

use App\Models\{Reservation, GuestDocument, Room};
use App\Services\{ReservationService, GuestService};
use App\Http\Requests\{StoreReservationRequest, UpdateReservationRequest};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Auth, Cache, Storage, DB, Log};
use Spatie\Activitylog\Facades\LogBatch;
use Exception;

class ReservationController extends Controller
{
    protected $reservationService;
    protected $guestService;

    public function __construct(ReservationService $reservationService, GuestService $guestService)
    {
        // حماية المسارات عبر Sanctum
        $this->middleware('auth:sanctum');
        $this->reservationService = $reservationService;
        $this->guestService = $guestService;
    }

    /**
     * عرض الحجوزات: HQ يرى الكل، والفرع يرى بياناته فقط
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        // مفتاح الكاش يعتمد على دور المستخدم وفرعه والصفحة الحالية
        $cacheKey = "res_list_u{$user->id}_b{$user->branch_id}_p" . $request->get('page', 1);

        $reservations = Cache::remember($cacheKey, 300, function () use ($user) {
            $query = Reservation::with(['occupants', 'room', 'branch', 'creator']);

            // إذا لم يكن مستخدماً مركزياً، قم بفلترة النتائج حسب الفرع المرتبط
            if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor', 'hq_security'])) {
                $query->where('branch_id', $user->branch_id);
            }

            return $query->latest()->paginate(15);
        });

        return response()->json(['status' => 'success', 'data' => $reservations]);
    }

    /**
     * تسجيل إقامة جديدة (Check-in)
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        // بدء عملية Transaction لضمان سلامة البيانات (إما أن يحفظ كل شيء أو لا شيء)
        return DB::transaction(function () use ($request) {
            try {
                LogBatch::startBatch();

                // 1. تجهيز بيانات الحجز 
                // نستخدم validated() لضمان مرور البيانات المفحوصة فقط
                $data = $request->validated();
                
                // التأكد من إرسال رقم السيارة للمصلحة (Service) بالمسمى الصحيح
                $data['vehicle_plate'] = $request->vehicle_plate ?? $request->car_plate_number;

                // إنشاء الحجز الأساسي عبر الخدمة
                $reservation = $this->reservationService->storeReservation($data);

                // 2. معالجة مصفوفة النزلاء (Occupants)
                if ($request->has('occupants')) {
                    foreach ($request->occupants as $index => $occupantData) {
                        
                        // إنشاء أو تحديث بيانات النزيل (مع فحص القائمة السوداء آلياً داخل الخدمة)
                        $guest = $this->guestService->storeOrUpdateGuest($occupantData);

                        // ربط النزيل بالحجز في الجدول الوسيط (reservation_guest)
                        $reservation->occupants()->attach($guest->id, [
                            'participant_type'         => $occupantData['is_primary'] ? 'primary' : 'companion',
                            'vehicle_plate_at_checkin' => $data['vehicle_plate'],
                            'registered_by'            => Auth::id(),
                        ]);

                        // 3. رفع وأرشفة صورة الهوية إذا وجدت
                        if ($request->hasFile("occupants.$index.id_image")) {
                            $this->reservationService->storeGuestDocument(
                                $guest->id,
                                $reservation->id,
                                $request->file("occupants.$index.id_image")
                            );
                        }
                    }
                }

                LogBatch::endBatch();
                
                // تنظيف الكاش لإظهار الحجز الجديد فوراً
                $this->clearReservationCache();

                return response()->json([
                    'status' => 'success', 
                    'message' => 'تم تسجيل الحجز والتسكين بنجاح.',
                    'data' => $reservation->load(['occupants', 'room'])
                ], 201);

            } catch (Exception $e) {
                // في حال حدوث أي خطأ، يتم التراجع عن كل ما تم حفظه في الـ Transaction
                Log::error("Reservation Store Error: " . $e->getMessage());
                return response()->json([
                    'status' => 'error', 
                    'message' => 'فشلت العملية: ' . $e->getMessage()
                ], 400);
            }
        });
    }

    /**
     * تسجيل خروج (Check-out)
     */
    public function checkOut(Reservation $reservation): JsonResponse
    {
        try {
            // التحقق من صلاحية التعديل (القيود الأمنية)
            if ($reservation->is_locked) {
                throw new Exception("هذا السجل مقفل أمنياً من قبل الإدارة المركزية ولا يمكن تعديله.");
            }
            
            $this->reservationService->checkOut($reservation);
            $this->clearReservationCache();

            return response()->json([
                'status' => 'success', 
                'message' => 'تم إنهاء الإقامة وتحرير الغرفة بنجاح.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * قفل/فك قفل السجل أمنياً (Security Lock)
     */
    public function toggleLock(Request $request, $id): JsonResponse
    {
        // التحقق من الرتبة: الموظف العادي لا يملك صلاحية القفل
        if (!Auth::user()->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_security'])) {
            return response()->json([
                'status' => 'error', 
                'message' => 'عذراً، لا تملك الصلاحيات الكافية لتنفيذ القفل الأمني.'
            ], 403);
        }
        
        $reservation = Reservation::findOrFail($id);
        $lockStatus = $request->boolean('lock'); 
        
        $reservation->update([
            'is_locked' => $lockStatus,
            'locked_by' => $lockStatus ? Auth::id() : null
        ]);
        
        $this->clearReservationCache();

        return response()->json([
            'status' => 'success', 
            'message' => $lockStatus ? 'تم تفعيل القفل الأمني بنجاح.' : 'تم فك القفل الأمني بنجاح.'
        ]);
    }

    /**
     * مسح الكاش لضمان تحديث البيانات في لوحات التحكم
     */
    protected function clearReservationCache()
    {
        // مسح الكاش العام (يفضل استخدام Tags في الإنتاج لزيادة الكفاءة)
        Cache::flush(); 
    }
}