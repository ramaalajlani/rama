<?php

namespace App\Services;

use App\Models\{Guest, Reservation, GuestDocument, Room, User};
use Illuminate\Support\Facades\{DB, Auth, Cache, Log, Storage};
use Illuminate\Support\Str;
use Carbon\Carbon;

class ReservationService
{
    protected $guestService;

    public function __construct(GuestService $guestService)
    {
        $this->guestService = $guestService;
    }

    /**
     * حساب إحصائيات اليوم - (تمت إضافتها لتتوافق مع طلبك السابق)
     */
    public function getDailyStats($branchId = null)
    {
        $branchId = $branchId ?? Auth::user()->branch_id;
        $today = Carbon::today();

        return [
            'check_ins_today' => Reservation::where('branch_id', $branchId)
                ->whereDate('check_in', $today)
                ->count(),

            'check_outs_today' => Reservation::where('branch_id', $branchId)
                ->whereDate('actual_check_out', $today)
                ->count(),

            'currently_in_house' => Reservation::where('branch_id', $branchId)
                ->currentlyIn() 
                ->count(),
        ];
    }

    /**
     * إنشاء حجز جديد (البند 3: الحالة الافتراضية "جديد")
     */
    public function storeReservation(array $data)
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();
            
            // منع الحجز المزدوج برمجياً
            $room = Room::lockForUpdate()->findOrFail($data['room_id']);

            // 1. عزل الفروع أمنياً
            if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_security']) && $room->branch_id !== $user->branch_id) {
                throw new \Exception("اختراق صلاحيات: لا يمكنك الوصول لغرف خارج نطاق فرعك التشغيلي.");
            }

            if ($room->status !== 'available') {
                throw new \Exception("عذراً، الغرفة رقم ({$room->room_number}) محجوزة حالياً.");
            }

            // 2. إنشاء الحجز مع ضبط حالة التدقيق كـ "جديد" والحالة 'confirmed' لظهور الإحصائيات
            $reservation = Reservation::create([
                'room_id'        => $data['room_id'],
                'branch_id'      => $data['branch_id'] ?? $user->branch_id, 
                'user_id'        => $user->id,   
                'check_in'       => $data['check_in'] ?? now(),
                'check_out'      => $data['check_out'],
                'status'         => $data['status'] ?? 'confirmed',
                'audit_status'   => 'new', 
                'is_locked'      => false, 
                'vehicle_plate'  => $data['vehicle_plate'] ?? ($data['car_plate_number'] ?? null),
                'security_notes' => $data['security_notes'] ?? null,
            ]);

            $room->update(['status' => 'occupied']);

            Log::info("Security Log: New Reservation #{$reservation->id} initialized by User #{$user->id}");

            return $reservation;
        });
    }

    /**
     * تحديث البيانات مع فرض "سبب التعديل" إذا كان الحجز مقفلاً (البند 4)
     */
    public function updateReservation(Reservation $reservation, array $data)
    {
        return DB::transaction(function () use ($reservation, $data) {
            $user = Auth::user();

            if ($reservation->is_locked) {
                if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor'])) {
                    throw new \Exception("هذا الحجز مقفل أمنياً؛ لا تملك صلاحية التعديل بعد التدقيق.");
                }

                if (empty($data['audit_notes'])) {
                    throw new \Exception("يجب إدخال سبب التعديل الاستثنائي للحجوزات المقفلة.");
                }
            }

            if (isset($data['room_id']) && $reservation->room_id != $data['room_id']) {
                $this->switchRooms($reservation->room_id, $data['room_id']);
            }

            $reservation->update($data);
            return $reservation;
        });
    }

    /**
     * عملية التدقيق والقفل (Core Audit Logic)
     */
    public function auditAndLock($reservationId, $notes = null)
    {
        return DB::transaction(function () use ($reservationId, $notes) {
            $reservation = Reservation::with('occupants')->findOrFail($reservationId);
            $user = Auth::user();

            // 1. فحص القائمة السوداء تلقائياً قبل الاعتماد
            foreach ($reservation->occupants as $guest) {
                if ($guest->is_blacklisted) {
                    $reservation->update(['audit_status' => 'flagged']);
                    Log::warning("Security Alert: Blacklisted guest detected in Reservation #{$reservationId}");
                    throw new \Exception("تنبيه أمني: لا يمكن تدقيق الحجز لوجود نزيل في القائمة السوداء.");
                }
            }

            // 2. تحديث الحالة للقفل النهائي
            $reservation->update([
                'audit_status' => 'audited',
                'is_locked'    => true,
                'audited_at'   => now(),
                'audited_by'   => $user->id,
                'locked_by'    => $user->id,
                'audit_notes'  => $notes
            ]);

            return $reservation;
        });
    }

    /**
     * إنهاء الإقامة (Check-out) 
     */
    public function checkOut(Reservation $reservation)
    {
        return DB::transaction(function () use ($reservation) {
            if ($reservation->is_locked && !Auth::user()->hasAnyRole(['hq_admin', 'hq_supervisor'])) {
                throw new \Exception("لا يمكن إنهاء الإقامة؛ الحجز قيد المراجعة الأمنية المركزية.");
            }

            $reservation->update([
                'status' => 'checked_out',
                'actual_check_out' => Carbon::now(),
            ]);

            $reservation->room->update(['status' => 'available']);
            
            return $reservation;
        });
    }

    protected function switchRooms($oldRoomId, $newRoomId)
    {
        Room::where('id', $oldRoomId)->update(['status' => 'available']);
        $newRoom = Room::lockForUpdate()->findOrFail($newRoomId);
        
        if ($newRoom->status !== 'available') {
            throw new \Exception("الغرفة الجديدة غير متاحة.");
        }
        
        $newRoom->update(['status' => 'occupied']);
    }

    /**
     * تخزين الوثائق (تم معالجة جميع حقول الـ SQL المطلوبة)
     */
    public function storeGuestDocument($guestId, $reservationId, $file)
    {
        // حساب البيانات لتجنب خطأ "Field doesn't have a default value"
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        $path = $file->storeAs(
            "security/docs/res_{$reservationId}", 
            Str::uuid() . '.' . $file->getClientOriginalExtension(), 
            'private' 
        );

        

        return GuestDocument::create([
            'reservation_id' => $reservationId,
            'guest_id'       => $guestId,
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'file_hash'      => $fileHash,
            'mime_type'      => $file->getClientMimeType() ?? $file->getMimeType(),
            'file_size'      => $file->getSize(),
            'document_type'  => 'identity_image',
            'uploaded_by'    => Auth::id()
        ]);
    }
}