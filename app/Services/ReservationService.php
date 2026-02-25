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
     * إنشاء حجز جديد مع التحقق من الصلاحيات وحالة الغرفة
     */
    public function storeReservation(array $data)
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();
            
            // قفل سجل الغرفة لمنع الحجز المزدوج (Race Conditions)
            // استخدام lockForUpdate يضمن عدم قدرة موظف آخر على حجزها في نفس اللحظة
            $room = Room::lockForUpdate()->findOrFail($data['room_id']);

            // 1. عزل الفروع: التأكد من أن الموظف يحجز في فرعه فقط (إلا إذا كان HQ)
            if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_security']) && $room->branch_id !== $user->branch_id) {
                throw new \Exception("اختراق صلاحيات: لا يمكنك الوصول لغرف خارج نطاق فرعك التشغيلي.");
            }

            // 2. التحقق من حالة الغرفة
            // تم تعديل الرسالة ليتم التقاطها في الواجهة الأمامية كـ "غرفة محجوزة"
            if ($room->status !== 'available') {
                throw new \Exception("عذراً، الغرفة رقم ({$room->room_number}) محجوزة حالياً لنزيل آخر.");
            }

            // 3. إنشاء سجل الحجز الأساسي
            // تصحيح: إضافة فحص مرن لجلب رقم السيارة من أي مسمى (vehicle_plate أو car_plate_number)
            $reservation = Reservation::create([
                'room_id'        => $data['room_id'],
                'branch_id'      => $data['branch_id'] ?? $user->branch_id, 
                'user_id'        => $user->id,   
                'check_in'       => $data['check_in'],
                'check_out'      => $data['check_out'],
                'status'         => $data['status'] ?? 'confirmed',
                'is_locked'      => false, 
                // هنا الحل الجذري لمشكلة عدم الحفظ:
                'vehicle_plate'  => $data['vehicle_plate'] ?? ($data['car_plate_number'] ?? null),
                'security_notes' => $data['security_notes'] ?? null,
            ]);

            // 4. تحديث حالة الغرفة
            $room->update(['status' => 'occupied']);

            Log::info("Security Log: New Reservation #{$reservation->id} created by User #{$user->id}");

            return $reservation;
        });
    }

    /**
     * تحديث بيانات حجز (مع احترام القفل الأمني المركزي)
     */
    public function updateReservation(Reservation $reservation, array $data)
    {
        return DB::transaction(function () use ($reservation, $data) {
            // التحقق من القفل الأمني قبل التعديل
            if ($reservation->is_locked && !Auth::user()->hasAnyRole(['hq_admin', 'hq_supervisor'])) {
                throw new \Exception("هذا الحجز مقفل أمنياً؛ يرجى مراجعة الإدارة المركزية لفك القفل أولاً.");
            }

            // إذا تغيرت الغرفة، نقوم بتحرير القديمة وحجز الجديدة
            if (isset($data['room_id']) && $reservation->room_id != $data['room_id']) {
                $this->switchRooms($reservation->room_id, $data['room_id']);
            }

            // تحديث رقم السيارة إذا كان موجوداً في طلب التعديل
            if (isset($data['car_plate_number'])) {
                $data['vehicle_plate'] = $data['car_plate_number'];
            }

            $reservation->update($data);
            return $reservation;
        });
    }

    /**
     * إنهاء الإقامة (Check-out)
     */
    public function checkOut(Reservation $reservation)
    {
        return DB::transaction(function () use ($reservation) {
            if ($reservation->is_locked) {
                throw new \Exception("لا يمكن إنهاء الإقامة؛ الحجز قيد المراجعة الأمنية والقفل مفعل.");
            }

            if ($reservation->status === 'checked_out') {
                throw new \Exception("هذا الحجز مغلق مسبقاً في النظام.");
            }

            $reservation->update([
                'status' => 'checked_out',
                'actual_check_out' => Carbon::now(),
            ]);

            // تحرير الغرفة وجعلها متاحة فوراً
            $reservation->room->update(['status' => 'available']);
            
            return $reservation;
        });
    }

    /**
     * تبديل الغرف: تحرير القديمة وقفل الجديدة
     */
    protected function switchRooms($oldRoomId, $newRoomId)
    {
        // جعل الغرفة القديمة متاحة
        Room::where('id', $oldRoomId)->update(['status' => 'available']);
        
        // قفل وفحص الغرفة الجديدة
        $newRoom = Room::lockForUpdate()->findOrFail($newRoomId);
        if ($newRoom->status !== 'available') {
            throw new \Exception("فشل التبديل: الغرفة الجديدة ({$newRoom->room_number}) غير متاحة.");
        }
        
        $newRoom->update(['status' => 'occupied']);
    }

    /**
     * أرشفة الوثائق الأمنية (Private Vault)
     */
    public function storeGuestDocument($guestId, $reservationId, $file)
    {
        // توليد بصمة رقمية للملف لضمان النزاهة الأمنية
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        // التخزين في مسار محمي
        // تأكدي أن 'private' معرف في config/filesystems.php
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
            'mime_type'      => $file->getMimeType(),
            'file_size'      => $file->getSize(),
            'document_type'  => 'identity_image',
            'uploaded_by'    => Auth::id()
        ]);
    }
}