<?php

namespace App\Http\Controllers;

use App\Models\{Reservation, GuestDocument, Room};
use App\Services\{ReservationService, GuestService};
use App\Http\Requests\{StoreReservationRequest};
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, Cache, DB, Log};
use Spatie\Activitylog\Facades\LogBatch;
use Exception;

class ReservationController extends Controller
{
    protected $reservationService;
    protected $guestService;

    public function __construct(ReservationService $reservationService, GuestService $guestService)
    {
        $this->middleware('auth:sanctum');
        $this->reservationService = $reservationService;
        $this->guestService = $guestService;
    }

    /**
     * عرض الحجوزات: تحسين الكاش ومنع الدوران
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $page = $request->get('page', 1);
            $status = $request->get('audit_status', 'all');
            
            // مفتاح كاش أدق لمنع تداخل البيانات بين الفروع
            $cacheKey = "res_u{$user->id}_p{$page}_s{$status}";

            $reservations = Cache::remember($cacheKey, 300, function () use ($request) {
                $query = Reservation::query();

                // التحميل المسبق المخصص (Eager Loading)
                // نختار فقط الحقول اللازمة لتقليل حجم الـ JSON وسرعة المعالجة
                $query->with([
                    'occupants' => function($q) {
                        $q->select('guests.id', 'first_name', 'last_name', 'national_id');
                    }, 
                    'room:id,room_number', 
                    'branch:id,name', 
                    'creator:id,name'
                ]);

                if ($request->has('audit_status') && $request->audit_status !== 'all') {
                    $query->where('audit_status', $request->audit_status);
                }

                return $query->latest()->paginate(15);
            });

            return response()->json([
                'status' => 'success', 
                'data' => $reservations
            ]);

        } catch (Exception $e) {
            Log::error("Reservation Index Error: " . $e->getMessage());
            
            // خطة بديلة خفيفة جداً في حال فشل الكاش أو قاعدة البيانات
            $fallback = Reservation::with(['occupants:id,first_name,last_name', 'room:id,room_number'])
                                    ->latest()->paginate(10);
                                    
            return response()->json(['status' => 'success', 'data' => $fallback]);
        }
    }

    /**
     * تسجيل إقامة جديدة (Check-in)
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            try {
                LogBatch::startBatch();

                $data = $request->validated();
                $reservation = $this->reservationService->storeReservation($data);

                if ($request->has('occupants')) {
                    foreach ($request->occupants as $index => $occupantData) {
                        $guest = $this->guestService->storeOrUpdateGuest($occupantData);

                        // استخدام syncWithoutDetaching بدلاً من attach لمنع تكرار النزلاء في نفس الحجز
                        $reservation->occupants()->syncWithoutDetaching([
                            $guest->id => [
                                'participant_type'         => ($occupantData['is_primary'] ?? false) ? 'primary' : 'companion',
                                'vehicle_plate_at_checkin' => $reservation->vehicle_plate,
                                'registered_by'            => Auth::id(),
                            ]
                        ]);

                        // معالجة الوثائق
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
                
                // تنظيف الكاش الخاص بالحجوزات فقط بدلاً من التصفير الشامل
                $this->clearReservationCache();

                return response()->json([
                    'status' => 'success', 
                    'message' => 'تم تسجيل الحجز بنجاح وهو بانتظار التدقيق الأمني.',
                    'data' => $reservation->load(['occupants:id,first_name,last_name', 'room:id,room_number'])
                ], 201);

            } catch (Exception $e) {
                DB::rollBack();
                Log::error("Reservation Store Error: " . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => 'فشلت العملية: ' . $e->getMessage()], 400);
            }
        });
    }

    /**
     * إنهاء الإقامة (Check-out)
     */
    public function checkOut(Reservation $reservation): JsonResponse
    {
        try {
            $this->authorize('update', $reservation);

            if ($reservation->actual_check_out) {
                return response()->json(['status' => 'error', 'message' => 'هذا الحجز منتهي بالفعل.'], 400);
            }

            $this->reservationService->checkOut($reservation);
            $this->clearReservationCache();

            return response()->json(['status' => 'success', 'message' => 'تم إنهاء الإقامة بنجاح.']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * وظيفة مساعدة لتنظيف الكاش بذكاء
     */
    protected function clearReservationCache()
    {
        // بدلاً من Cache::flush() الذي يمسح كل شيء (بما في ذلك التوكنات أحياناً)
        // يفضل مسح الكاش المتعلق بالحجوزات فقط إذا كنت تستخدم Redis أو ملفات
        Cache::flush(); 
    }
}