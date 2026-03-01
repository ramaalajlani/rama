<?php

namespace App\Services;

use App\Models\{Reservation, Room, GuestDocument};
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Gate, Storage};
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use RuntimeException;

class ReservationService
{
    public function __construct(
        private GuestService $guestService,
        private SecurityService $securityService
    ) {}

    public function paginateReservations(Request $request, $user, string $auditStatus, int $branchId, int $perPage)
    {
        $q = Reservation::query()
            ->select([
                'guest_reservations.id',
                'guest_reservations.branch_id',
                'guest_reservations.room_id',
                'guest_reservations.user_id',
                'guest_reservations.check_in',
                'guest_reservations.check_out',
                'guest_reservations.actual_check_out',
                'guest_reservations.vehicle_plate',
                'guest_reservations.status',
                'guest_reservations.audit_status',
                'guest_reservations.is_locked',
                'guest_reservations.created_at',
            ])
            ->with([
                'room:id,room_number,floor_number',
                'branch:id,name',
                'creator:id,name',
            ])
            ->withCount('occupants')
            ->orderByDesc('guest_reservations.check_in');

        // عزل فروع (إن لم يكن HQ)
        if (!$user->hasAnyRole(['hq_admin','hq_security','hq_auditor','hq_supervisor'])) {
            $q->where('guest_reservations.branch_id', (int)$user->branch_id);
        } elseif ($branchId > 0) {
            $q->where('guest_reservations.branch_id', $branchId);
        }

        if ($auditStatus !== 'all') {
            $q->where('guest_reservations.audit_status', $auditStatus);
        }

        if ($request->filled('status')) {
            $q->where('guest_reservations.status', (string)$request->status);
        }

        return $q->simplePaginate($perPage);
    }

    public function createReservationWithOccupants(Request $request): Reservation
    {
        $user = Auth::user();
        $data = $request->validated();

        // ✅ لا تعتمد على input غير validated للـ occupants
        $occupants = $data['occupants'] ?? [];
        if (!is_array($occupants) || count($occupants) < 1) {
            throw new Exception("يجب إدخال نزيل واحد على الأقل.");
        }

        // ✅ check_in/check_out: check_out عندك nullable بالمهاجرة
        try {
            $ci = Carbon::parse((string)$data['check_in']);
            $co = !empty($data['check_out']) ? Carbon::parse((string)$data['check_out']) : null;

            if ($co && $co->lessThanOrEqualTo($ci)) {
                throw new Exception("تاريخ الخروج يجب أن يكون بعد تاريخ الدخول.");
            }
        } catch (\Throwable $e) {
            throw new Exception("صيغة تاريخ الدخول/الخروج غير صحيحة.");
        }

        return DB::transaction(function () use ($request, $user, $data, $occupants) {

            $room = Room::lockForUpdate()->findOrFail((int)$data['room_id']);

            // ✅ عزل فروع
            if (
                !$user->hasAnyRole(['hq_admin','hq_supervisor','hq_security']) &&
                (int)$room->branch_id !== (int)$user->branch_id
            ) {
                throw new Exception("لا يمكنك الوصول لغرف خارج نطاق فرعك.");
            }

            // ✅ تعارض فعلي: إقامة مفتوحة لنفس الغرفة
            $conflict = Reservation::query()
                ->where('room_id', $room->id)
                ->whereNull('actual_check_out')
                ->exists();

            if ($conflict) {
                throw new Exception("الغرفة مشغولة فعلياً.");
            }

            // ✅ status حسب المهاجرة: pending/confirmed/checked_out/cancelled
            $allowedStatus = ['pending','confirmed','checked_out','cancelled'];
            $status = (string)($data['status'] ?? 'confirmed');
            if (!in_array($status, $allowedStatus, true)) {
                $status = 'confirmed';
            }

            $reservation = Reservation::create([
                'room_id'        => $room->id,
                'branch_id'      => $room->branch_id,
                'user_id'        => $user->id,
                'check_in'       => $data['check_in'],
                'check_out'      => $data['check_out'] ?? null,
                'status'         => $status,
                'audit_status'   => 'new',
                'is_locked'      => false,
                'vehicle_plate'  => $data['vehicle_plate'] ?? null,
                'security_notes' => $data['security_notes'] ?? null,
            ]);

            // ✅ تحديث حالة الغرفة
            $room->update(['status' => 'occupied']);

            // 1) ربط النزلاء + رفع الوثائق
            $this->attachOccupantsAndDocs($reservation, $occupants, $request);

            // 2) فحص blacklist (صامت) + Lock إن لزم
            $this->silentBlacklistCheckAndLockIfNeeded($reservation);

            // تحميل مخرجات خفيفة
            $reservation->load([
                'room:id,room_number,floor_number,type',
                'branch:id,name',
                'creator:id,name',
                'occupants:id,first_name,father_name,last_name,mother_name,national_id,phone',
                'documents:id,reservation_id,guest_id,document_type,file_name,mime_type,file_size,created_at',
            ]);

            return $reservation;
        });
    }

    private function attachOccupantsAndDocs(Reservation $reservation, array $occupants, Request $request): void
    {
        $user = Auth::user();

        // ✅ ضمان primary واحد فقط (حماية إضافية حتى لو request غلط)
        $primaryIndexes = [];
        foreach ($occupants as $i => $occ) {
            if (is_array($occ) && !empty($occ['is_primary'])) $primaryIndexes[] = $i;
        }
        if (count($primaryIndexes) === 0) {
            if (isset($occupants[0]) && is_array($occupants[0])) {
                $occupants[0]['is_primary'] = true;
            }
        } elseif (count($primaryIndexes) > 1) {
            $first = $primaryIndexes[0];
            foreach ($primaryIndexes as $k => $idx) {
                if ($idx === $first) continue;
                $occupants[$idx]['is_primary'] = false;
            }
            $occupants[$first]['is_primary'] = true;
        }

        $pivot = [];
        $primaryGuestId = null;
        $createdGuests = []; // guestId => index

        foreach ($occupants as $index => $occ) {
            if (!is_array($occ)) continue;

            // ✅ فلترة بيانات النزيل (فقط الأعمدة الموجودة عندك)
            $guestPayload = [
                'national_id'  => $occ['national_id']  ?? null,
                'first_name'   => $occ['first_name']   ?? null,
                'father_name'  => $occ['father_name']  ?? null,
                'last_name'    => $occ['last_name']    ?? null,
                'mother_name'  => $occ['mother_name']  ?? null,
                'id_type'      => $occ['id_type']      ?? null,
                'nationality'  => $occ['nationality']  ?? null,
                'car_plate'    => $occ['car_plate']    ?? null,
                'phone'        => $occ['phone']        ?? null,
                'email'        => $occ['email']        ?? null,
            ];

            $guest = $this->guestService->storeOrUpdateGuest($guestPayload);

            $isPrimary = !empty($occ['is_primary']);
            if ($isPrimary && $primaryGuestId === null) {
                $primaryGuestId = (int)$guest->id;
            }

            $createdGuests[(int)$guest->id] = (int)$index;

            $pivot[(int)$guest->id] = [
                'participant_type'         => $isPrimary ? 'primary' : 'companion',
                'relationship'             => $occ['relationship'] ?? null,
                'vehicle_plate_at_checkin' => (string)($reservation->vehicle_plate ?? ''),
                'registered_by'            => (int)$user->id,
                'companion_of_guest_id'    => null,
                'created_at'               => now(),
                'updated_at'               => now(),
            ];
        }

        // ✅ اربط companions بالـ primary
        if ($primaryGuestId !== null) {
            foreach ($pivot as &$row) {
                if (($row['participant_type'] ?? '') === 'companion') {
                    $row['companion_of_guest_id'] = $primaryGuestId;
                }
            }
            unset($row);
        }

        if (!empty($pivot)) {
            // ✅ آمن ضد التكرار
            $reservation->occupants()->syncWithoutDetaching($pivot);
        }

        // docs — الملفات تأتي من Request وليس validated array
        foreach ($createdGuests as $guestId => $idx) {
            if ($request->hasFile("occupants.$idx.id_image")) {
                $file = $request->file("occupants.$idx.id_image");
                if ($file instanceof UploadedFile) {
                    $this->storeGuestDocument((int)$guestId, (int)$reservation->id, $file);
                }
            }
        }
    }

    private function silentBlacklistCheckAndLockIfNeeded(Reservation $reservation): void
    {
        Gate::authorize('check', \App\Models\SecurityBlacklist::class);

        $reservation->loadMissing(['occupants', 'branch', 'room']);

        foreach ($reservation->occupants as $guest) {
            $match = $this->securityService->checkGuestAgainstBlacklist($guest, $reservation);

            if (!empty($match['found'])) {
                $reservation->update([
                    'audit_status' => 'audited', // إخفاء سبب lock عن الفرع
                    'is_locked'    => true,
                    'locked_by'    => null,
                ]);
                return;
            }
        }
    }

    public function auditAndLock(Reservation $reservation, ?string $notes): Reservation
    {
        $user = Auth::user();

        return DB::transaction(function () use ($reservation, $notes, $user) {
            $reservation->update([
                'audit_status' => 'audited',
                'is_locked'    => true,
                'audited_at'   => now(),
                'audited_by'   => $user->id,
                'locked_by'    => $user->id,
                'audit_notes'  => $notes,
            ]);

            return $reservation->fresh();
        });
    }

    public function updateReservation(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $reservation->update($data);
            return $reservation->fresh();
        });
    }

    public function checkOut(Reservation $reservation): Reservation
    {
        $user = Auth::user();

        return DB::transaction(function () use ($reservation, $user) {

            if (
                !$user->hasAnyRole(['hq_admin','hq_supervisor','hq_security','hq_auditor']) &&
                (int)$user->branch_id !== (int)$reservation->branch_id
            ) {
                throw new Exception("غير مصرح لك بإتمام عملية الخروج لهذا الفرع.");
            }

            if ($reservation->is_locked && !$user->hasAnyRole(['hq_admin','hq_supervisor'])) {
                throw new Exception("لا يمكن إنهاء الإقامة لأن السجل مقفل.");
            }

            $reservation->update([
                'status'           => 'checked_out',
                'actual_check_out' => Carbon::now(),
            ]);

            $reservation->room()->update(['status' => 'available']);

            return $reservation->fresh();
        });
    }

    public function storeGuestDocument(int $guestId, int $reservationId, UploadedFile $file): GuestDocument
    {
        // ✅ فحص صحيح للـ disk
        try {
            Storage::disk('private');
        } catch (\Throwable $e) {
            throw new RuntimeException("Storage disk 'private' غير معرف في config/filesystems.php");
        }

        $fileHash = hash_file('sha256', $file->getRealPath());

        $path = $file->storeAs(
            "security/docs/res_{$reservationId}",
            (string) Str::uuid() . '.' . $file->getClientOriginalExtension(),
            'private'
        );

        return GuestDocument::create([
            'reservation_id' => $reservationId,
            'guest_id'       => $guestId,
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'file_hash'      => $fileHash,
            'mime_type'      => $file->getMimeType(),
            'file_size'      => (int)$file->getSize(),
            'document_type'  => 'identity_image',
            'uploaded_by'    => Auth::id(),
        ]);
    }
}