<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RoomService
{
    /**
     * جلب الغرف حسب نطاق المستخدم + فلاتر
     */
    public function getRoomsForUser(array $filters = []): LengthAwarePaginator
    {
        $user = Auth::user();

        $perPage = (int)($filters['per_page'] ?? 20);
        if ($perPage < 1) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $q = Room::query()
            ->select([
                'id',
                'branch_id',
                'room_number',
                'floor_number',
                'type',
                'status',
                'description',
                'created_at'
            ])
            ->with(['branch:id,name'])
            ->whereNull('deleted_at')
            ->orderBy('floor_number')
            ->orderBy('room_number');

        /*
        |------------------------------------------------------
        | 🔒 عزل الفروع
        |------------------------------------------------------
        */
        $isHQ = $user->hasAnyRole(['hq_supervisor','hq_auditor']);

        if (!$isHQ) {
            $q->where('branch_id', (int)$user->branch_id);
        } else {
            if (!empty($filters['branch_id'])) {
                $q->where('branch_id', (int)$filters['branch_id']);
            }
        }

        /*
        |------------------------------------------------------
        | 🎯 فلاتر إضافية
        |------------------------------------------------------
        */
        if (!empty($filters['status'])) {
            $q->where('status', (string)$filters['status']);
        }

        if (!empty($filters['floor_number'])) {
            $q->where('floor_number', (int)$filters['floor_number']);
        }

        if (!empty($filters['type'])) {
            $q->where('type', (string)$filters['type']);
        }

        return $q->paginate($perPage);
    }

    /**
     * إنشاء غرفة جديدة
     */
    public function createRoom(array $data): Room
    {
        $user = Auth::user();

        return DB::transaction(function () use ($data, $user) {

            // 🔒 منع إنشاء غرفة خارج نطاق الفرع
            if ($user->hasRole('branch_reception')) {
                $data['branch_id'] = (int)$user->branch_id;
            }

            return Room::create([
                'branch_id'   => $data['branch_id'],
                'room_number' => $data['room_number'],
                'floor_number'=> $data['floor_number'],
                'type'        => $data['type'],
                'status'      => $data['status'] ?? 'available',
                'description' => $data['description'] ?? null,
            ]);
        });
    }

    /**
     * تحديث بيانات غرفة
     */
    public function updateRoom(Room $room, array $data): Room
    {
        return DB::transaction(function () use ($room, $data) {

            $room->update([
                'room_number' => $data['room_number'] ?? $room->room_number,
                'floor_number'=> $data['floor_number'] ?? $room->floor_number,
                'type'        => $data['type'] ?? $room->type,
                'status'      => $data['status'] ?? $room->status,
                'description' => $data['description'] ?? $room->description,
            ]);

            return $room->fresh();
        });
    }

    /**
     * تحديث حالة الغرفة فقط (endpoint منفصل)
     */
    public function updateStatus(Room $room, string $status): Room
    {
        return DB::transaction(function () use ($room, $status) {

            $room->update([
                'status' => $status
            ]);

            return $room->fresh();
        });
    }

    /**
     * حذف منطقي (Soft Delete)
     */
    public function softDelete(Room $room): void
    {
        $room->delete();
    }
}