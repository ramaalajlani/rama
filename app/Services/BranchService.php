<?php
// app/Services/BranchService.php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function getAllBranchesWithStats(int $perPage = 20, bool $onlyActive = false)
    {
        $q = Branch::query()
            ->select(['id', 'name', 'city', 'manager_name', 'address', 'phone', 'status', 'created_at'])
            ->withCount([
                'rooms',
                'rooms as occupied_rooms_count' => fn($qq) => $qq->where('status', 'occupied'),
                'rooms as maintenance_rooms_count' => fn($qq) => $qq->where('status', 'maintenance'),
                'reservations as active_reservations' => function ($qq) {
                    $qq->where('status', 'confirmed')
                       ->whereDate('check_in', '<=', now())
                       ->whereDate('check_out', '>=', now())
                       ->whereNull('actual_check_out');
                },
            ])
            ->latest('id');

        if ($onlyActive) {
            $q->where('status', 'active');
        }

        return $q->paginate($perPage);
    }

    public function createBranch(array $data): Branch
    {
        return DB::transaction(fn() => Branch::create($data));
    }

    public function updateBranch(Branch $branch, array $data): bool
    {
        return $branch->update($data);
    }

    public function getBranchDetails(Branch $branch): Branch
    {
        // ✅ شلت creator لأنه غير موجود بالموديل
        return $branch->load(['rooms']);
    }
}