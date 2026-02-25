<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class BranchService
{
    /**
     * جلب الفروع مع إحصائيات أمنية وتشغيلية كاملة للـ HQ
     */
    public function getAllBranchesWithStats()
    {
        return Branch::withCount([
            'rooms', 
            // إحصائية الغرف المسكونة حالياً
            'rooms as occupied_rooms_count' => function ($query) {
                $query->where('status', 'occupied');
            },
            // إحصائية الغرف التي تحت الصيانة (أمنية أو فنية)
            'rooms as maintenance_rooms_count' => function ($query) {
                $query->where('status', 'maintenance');
            }
        ])
        // حساب عدد الحجوزات النشطة في هذا الفرع الآن
        ->withCount(['reservations as active_reservations' => function ($query) {
            $query->where('status', 'confirmed')
                  ->whereDate('check_in', '<=', now())
                  ->whereDate('check_out', '>=', now());
        }])
        ->latest()
        ->get();
    }

    /**
     * إنشاء فرع جديد
     */
    public function createBranch(array $data)
    {
        return DB::transaction(function () use ($data) {
            return Branch::create($data);
        });
    }

    /**
     * تحديث بيانات الفرع
     */
    public function updateBranch(Branch $branch, array $data)
    {
        return $branch->update($data);
    }

    /**
     * جلب تقرير سريع لفرع محدد (للمدققين)
     */
    public function getBranchDetails(Branch $branch)
    {
        return $branch->load(['rooms', 'creator']);
    }
}